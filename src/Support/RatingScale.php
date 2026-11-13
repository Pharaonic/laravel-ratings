<?php

namespace Pharaonic\Laravel\Ratings\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * The values a rating may take, and the exact decimal arithmetic behind them.
 *
 * Every number is handled as a decimal string with up to DECIMALS decimal
 * places and WHOLE_DIGITS whole digits (the value column is DECIMAL(10,4)),
 * and compared as an integer count of 0.0001 units, so 0.1 + 0.2 never
 * drifts away from 0.3 and a step of 0.5 is checked exactly.
 *
 * @internal
 */
final class RatingScale
{
    /**
     * The decimal places a rating value may have.
     */
    public const DECIMALS = 4;

    /**
     * The whole digits a rating value may have.
     */
    public const WHOLE_DIGITS = 6;

    /**
     * One unit of the scaled integers: 10 ** DECIMALS.
     */
    private const UNITS = 10000;

    private function __construct(
        private int $min,
        private int $max,
        private int $step,
    ) {}

    /**
     * The scale of the rateable: its ratingScale() when it has one, the configured scale otherwise.
     *
     * @throws UnexpectedValueException when the scale is not valid
     */
    public static function for(Model $rateable): self
    {
        if (! method_exists($rateable, 'ratingScale')) {
            return self::configured();
        }

        return self::fromArray($rateable->ratingScale(), sprintf('The ratingScale() of [%s]', get_class($rateable)));
    }

    /**
     * The scale from the pharaonic.ratings min, max and step settings.
     *
     * @throws UnexpectedValueException when the settings are not a valid scale
     */
    public static function configured(): self
    {
        return self::fromArray([
            'min' => Config::get('pharaonic.ratings.min', 1),
            'max' => Config::get('pharaonic.ratings.max', 5),
            'step' => Config::get('pharaonic.ratings.step', 1),
        ], 'The pharaonic.ratings min, max and step settings');
    }

    /**
     * A scale from an array with min, max and step.
     *
     * @param  string  $source  what the array came from, for the error message
     *
     * @throws UnexpectedValueException when it is not a valid scale
     */
    public static function fromArray(mixed $scale, string $source): self
    {
        if (! is_array($scale)) {
            throw new UnexpectedValueException(sprintf(
                '%s must be an array with min, max and step, not %s.', $source, get_debug_type($scale)
            ));
        }

        $numbers = [];

        foreach (['min', 'max', 'step'] as $key) {
            if (! array_key_exists($key, $scale)) {
                throw new UnexpectedValueException(sprintf('%s must have a [%s] key.', $source, $key));
            }

            try {
                $numbers[$key] = self::scaled(self::normalize($scale[$key], $key));
            } catch (InvalidArgumentException $exception) {
                throw new UnexpectedValueException($source.': '.$exception->getMessage(), 0, $exception);
            }
        }

        if ($numbers['step'] <= 0) {
            throw new UnexpectedValueException(sprintf('%s: the step must be greater than 0.', $source));
        }

        if ($numbers['max'] <= $numbers['min']) {
            throw new UnexpectedValueException(sprintf('%s: max must be greater than min.', $source));
        }

        if (($numbers['max'] - $numbers['min']) % $numbers['step'] !== 0) {
            throw new UnexpectedValueException(sprintf(
                '%s: max - min must be a whole number of steps, so that max is a valid rating.', $source
            ));
        }

        return new self($numbers['min'], $numbers['max'], $numbers['step']);
    }

    /**
     * Whether the value, a canonical decimal string, is on the scale.
     */
    public function allows(string $value): bool
    {
        $value = self::scaled($value);

        return $value >= $this->min && $value <= $this->max && ($value - $this->min) % $this->step === 0;
    }

    /**
     * Refuse a value that is not on the scale.
     *
     * @throws InvalidArgumentException when it is not
     */
    public function assertAllows(string $value, Model $rateable): void
    {
        if (! $this->allows($value)) {
            throw new InvalidArgumentException(sprintf(
                'The rating [%s] is not on the rating scale of [%s]: %s.', $value, get_class($rateable), $this->describe()
            ));
        }
    }

    /**
     * The scale as numbers.
     *
     * @return array{min: int|float, max: int|float, step: int|float}
     */
    public function toArray(): array
    {
        return [
            'min' => self::toNumber(self::unscaled($this->min)),
            'max' => self::toNumber(self::unscaled($this->max)),
            'step' => self::toNumber(self::unscaled($this->step)),
        ];
    }

    /**
     * The scale in words, such as "1 to 5 in steps of 1".
     */
    public function describe(): string
    {
        return sprintf(
            '%s to %s in steps of %s', self::unscaled($this->min), self::unscaled($this->max), self::unscaled($this->step)
        );
    }

    /**
     * A number as its canonical decimal string: no leading or trailing zeros,
     * no "+", no "-0", such as "4", "4.5" or "-0.25".
     *
     * Integers, finite floats, and plain decimal strings ("4", "4.5", "-1.25")
     * are accepted. A float is taken at the decimal it stands for, so
     * 0.1 + 0.2 is 0.3, but a float that needs more decimal places is refused.
     *
     * @param  string  $what  what the number is, for the error message
     *
     * @throws InvalidArgumentException when it is not such a number, has more
     *                                  than DECIMALS decimal places, or more than WHOLE_DIGITS whole digits
     */
    public static function normalize(mixed $value, string $what = 'rating'): string
    {
        if (is_int($value)) {
            $string = (string) $value;
        } elseif (is_float($value)) {
            $string = self::fromFloat($value, $what);
        } elseif (is_string($value)) {
            $string = $value;
        } else {
            throw new InvalidArgumentException(sprintf(
                'The %s must be an integer, a float, or a decimal string, not %s.', $what, get_debug_type($value)
            ));
        }

        if (preg_match('/^([+-]?)([0-9]+)(?:\.([0-9]+))?$/', $string, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'The %s must be a decimal number such as 4 or 4.5, not [%s].', $what, self::excerpt($string)
            ));
        }

        $whole = ltrim($matches[2], '0');
        $fraction = rtrim($matches[3] ?? '', '0');

        if (strlen($fraction) > self::DECIMALS) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] has more than %d decimal places.', $what, self::excerpt($string), self::DECIMALS
            ));
        }

        if (strlen($whole) > self::WHOLE_DIGITS) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] is out of range: it may have up to %d whole digits.', $what, self::excerpt($string), self::WHOLE_DIGITS
            ));
        }

        $canonical = ($whole === '' ? '0' : $whole).($fraction === '' ? '' : '.'.$fraction);

        return $matches[1] === '-' && $canonical !== '0' ? '-'.$canonical : $canonical;
    }

    /**
     * A value or sum the database returned, as a canonical decimal string.
     * Null stays null. A float, or a string with more decimal places, is
     * rounded to DECIMALS places (SQLite sums floats).
     *
     * @throws UnexpectedValueException when it is not a number
     */
    public static function fromDatabase(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match('/^[+-]?[0-9]+(\.[0-9]+)?$/', $value) === 1 && strlen((string) strrchr($value, '.')) > self::DECIMALS + 1) {
            $value = (float) $value;
        }

        if (is_float($value) && is_finite($value)) {
            $value = number_format($value, self::DECIMALS, '.', '');
        }

        try {
            return self::normalize($value, 'stored rating');
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * A canonical decimal string as a number: an int when it is whole, a float otherwise.
     */
    public static function toNumber(string $value): int|float
    {
        return str_contains($value, '.') ? (float) $value : (int) $value;
    }

    /**
     * A canonical decimal string as an integer count of 0.0001 units.
     */
    private static function scaled(string $value): int
    {
        $negative = $value[0] === '-';
        $unsigned = ltrim($value, '-');
        $dot = strpos($unsigned, '.');
        $whole = $dot === false ? $unsigned : substr($unsigned, 0, $dot);
        $fraction = $dot === false ? '' : substr($unsigned, $dot + 1);
        $scaled = (int) $whole * self::UNITS + (int) str_pad($fraction, self::DECIMALS, '0');

        return $negative ? -$scaled : $scaled;
    }

    /**
     * An integer count of 0.0001 units as a canonical decimal string.
     */
    private static function unscaled(int $value): string
    {
        $string = sprintf('%d.%04d', intdiv(abs($value), self::UNITS), abs($value) % self::UNITS);

        return ($value < 0 ? '-' : '').rtrim(rtrim($string, '0'), '.');
    }

    /**
     * The decimal a float stands for, up to DECIMALS places.
     *
     * The float is rounded to DECIMALS places and kept only when that decimal
     * is the float up to binary rounding (12 significant digits), so 4.5 and
     * 0.1 + 0.2 pass, and 4.55555 does not. number_format() ignores the locale.
     *
     * @throws InvalidArgumentException when the float is not finite or needs more places
     */
    private static function fromFloat(float $value, string $what): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException(sprintf('The %s must be a finite number, not [%s].', $what, var_export($value, true)));
        }

        $rounded = number_format($value, self::DECIMALS, '.', '');

        if (abs((float) $rounded - $value) > max(abs($value), 1.0) * 1e-12) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] has more than %d decimal places.', $what, var_export($value, true), self::DECIMALS
            ));
        }

        return $rounded;
    }

    private static function excerpt(string $value): string
    {
        return strlen($value) > 40 ? substr($value, 0, 40).'...' : $value;
    }
}
