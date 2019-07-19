<?php declare(strict_types=1);

namespace PAR\Enum;

use PAR\Core\ComparableInterface;
use PAR\Core\Exception\ClassMismatchException;
use PAR\Core\Helper\ClassHelper;
use PAR\Core\ObjectCastToString;
use PAR\Core\ObjectInterface;
use PAR\Enum\Exception\CloneNotSupportedException;
use PAR\Enum\Exception\InvalidClassException;
use PAR\Enum\Exception\MissingConstantsException;
use PAR\Enum\Exception\UnknownEnumException;
use ReflectionClass;
use Serializable;

abstract class Enum implements Enumerable, ObjectInterface, ComparableInterface, Serializable
{
    use ObjectCastToString;

    /**
     * @var array<string, array<int, array>>
     */
    private static $configuration = [];

    /**
     * @var array<string, bool>
     */
    private static $allInstancesLoaded = [];

    /**
     * @var array<string, array<string, static>>
     */
    private static $instances = [];

    /**
     * @var string
     */
    private $name;

    /**
     * @var int
     */
    private $ordinal;

    /**
     * Maps static methods calls to instances.
     *
     * @param string $name      The name of the instance.
     * @param array  $arguments Ignored.
     *
     * @return static
     * @throws InvalidClassException
     * @throws MissingConstantsException
     */
    final public static function __callStatic(string $name, array $arguments): self
    {
        return static::valueOf($name);
    }

    /**
     * Returns the enum element of the specified enum type with the specified name. The name must match exactly an
     * identifier used to declare an enum element in this type. (Extraneous whitespace characters are not permitted.)
     *
     * @param string $name The name of the element to return
     *
     * @return static
     * @throws InvalidClassException
     * @throws MissingConstantsException
     * @throws UnknownEnumException
     */
    public static function valueOf(string $name): self
    {
        if (isset(self::$instances[static::class][$name])) {
            return self::$instances[static::class][$name];
        }

        $configuration = self::configuration();

        if (array_key_exists($name, $configuration)) {
            [$ordinal, $arguments] = $configuration[$name];

            return self::createValue($name, $ordinal, $arguments);
        }

        throw UnknownEnumException::withName(static::class, $name);
    }

    /**
     * Returns an array containing the elements of this enum type, in the order they are declared.
     *
     * @return array<static>
     * @throws InvalidClassException
     * @throws MissingConstantsException
     */
    public static function values(): array
    {
        if (isset(self::$allInstancesLoaded[static::class])) {
            return self::$instances[static::class];
        }

        if (!isset(self::$instances[static::class])) {
            self::$instances[static::class] = [];
        }

        foreach (self::configuration() as $name => $configuration) {
            if (array_key_exists($name, self::$instances[static::class])) {
                continue;
            }

            [$ordinal, $arguments] = $configuration;

            static::createValue($name, $ordinal, $arguments);
        }

        uasort(
            self::$instances[static::class],
            static function (self $a, self $b) {
                return $a->ordinal() <=> $b->ordinal();
            }
        );

        self::$allInstancesLoaded[static::class] = true;

        return array_values(self::$instances[static::class]);
    }

    /**
     * @throws CloneNotSupportedException
     */
    final public function __clone()
    {
        throw CloneNotSupportedException::for($this);
    }

    /**
     * Compares this object with with other object. Returns a negative integer, zero or a positive integer as this
     * object is less than, equals to, or greater then the other object.
     *
     * @param ComparableInterface $other The other object to be compared.
     *
     * @return int
     * @throws ClassMismatchException If the other object's type prevents it from being compared to this object.
     */
    final public function compareTo(ComparableInterface $other): int
    {
        if ($other instanceof self && get_class($other) === static::class) {
            return $this->ordinal() - $other->ordinal();
        }

        throw ClassMismatchException::expectedInstance($this, $other);
    }

    /**
     * Determines if this object equals provided value.
     *
     * @param mixed $other The other value to compare with.
     *
     * @return bool
     */
    final public function equals($other): bool
    {
        if ($other instanceof self && get_class($other) === static::class) {
            return $this->ordinal === $other->ordinal;
        }

        return false;
    }

    /**
     * Returns the name of this enum element, exactly as declared in its declaration.
     *
     * @return string
     */
    final public function name(): string
    {
        return $this->name;
    }

    /**
     * Returns the ordinal of this enum element (its position in its declaration, where the initial element is assigned an ordinal of zero).
     *
     * @return int
     */
    final public function ordinal(): int
    {
        return $this->ordinal;
    }

    /**
     * @inheritDoc
     */
    final public function serialize()
    {
        return $this->name();
    }

    /**
     * Returns the name of this enum constant, exactly as declared in its declaration.
     *
     * @return string
     */
    final public function toString(): string
    {
        return $this->name();
    }

    /**
     * @inheritDoc
     */
    final public function unserialize($serialized)
    {
        $configuration = self::configuration();

        if (!array_key_exists($serialized, $configuration)) {
            throw UnknownEnumException::withName(static::class, $serialized);
        }

        [$ordinal, $arguments] = $configuration[$serialized];

        $this->name = $serialized;
        $this->ordinal = $ordinal;

        if (!empty($arguments)) {
            $reflectionClass = ClassHelper::getReflectionClass(static::class);
            $constructor = $reflectionClass->getConstructor();
            if ($constructor->getParameters()) {
                $constructor->setAccessible(true);
                $constructor->invokeArgs($this, $arguments);
                $constructor->setAccessible(false);
            }
        }
    }

    /**
     * @return array
     * @throws InvalidClassException
     * @throws MissingConstantsException
     */
    private static function configuration(): array
    {
        if (isset(self::$configuration[static::class])) {
            return self::$configuration[static::class];
        }

        self::$configuration[static::class] = [];

        $reflectionClass = new ReflectionClass(static::class);
        if (!$reflectionClass->isAbstract() && !$reflectionClass->isFinal()) {
            throw new InvalidClassException(static::class);
        }

        $constants = [];
        foreach ($reflectionClass->getReflectionConstants() as $reflectionClassConstant) {
            if (!$reflectionClassConstant->isProtected()) {
                continue;
            }

            $value = $reflectionClassConstant->getValue();
            $constants[$reflectionClassConstant->getName()] = is_array($value) ? $value : [];
        }

        $methods = self::resolveMethodsFromDocBlock($reflectionClass);

        // Validate all (or none of the) methods have a constant value
        $missingConstants = array_diff($methods, array_keys($constants));
        $numMissingConstants = count($missingConstants);
        if ($numMissingConstants > 0 && $numMissingConstants !== count($methods)) {
            throw new MissingConstantsException(static::class, $missingConstants);
        }

        $ordinal = -1;
        foreach ($methods as $methodName) {
            self::$configuration[static::class][$methodName] = [
                ++$ordinal,
                $constants[$methodName] ?? [],
            ];
        }

        return self::$configuration[static::class];
    }

    private static function resolveMethodsFromDocBlock(ReflectionClass $reflection): array
    {
        $values = [];
        $docComment = $reflection->getDocComment();
        if (!$docComment) {
            return $values;
        }

        preg_match_all('/\@method\s+static\s+self\s+([\w]+)\(\s*?\)/', $docComment, $matches);
        foreach ($matches[1] ?? [] as $value) {
            $values[] = $value;
        }

        return $values;
    }

    private static function createValue(string $name, int $ordinal, array $arguments): self
    {
        /**
         * The default implementation does not accept any arguments
         *
         * @noinspection PhpMethodParametersCountMismatchInspection
         */
        $instance = new static(...$arguments);
        $instance->name = $name;
        $instance->ordinal = $ordinal;

        return self::$instances[static::class][$name] = $instance;
    }

    /**
     * The constructor is private by default to avoid arbitrary enum creation.
     *
     * When creating your own constructor for a parameterized enum, make sure to declare it as protected, so that the
     * static methods are able to construct it. Do not make it public, as that would allow creation of non-singleton
     * enum instances.
     */
    protected function __construct()
    {
    }
}
