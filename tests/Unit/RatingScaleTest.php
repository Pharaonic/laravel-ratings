<?php

use Pharaonic\Laravel\Ratings\Support\RatingScale;

test('numbers normalize to canonical decimal strings', function (mixed $value, string $canonical) {
    $this->assertSame($canonical, RatingScale::normalize($value));
})->with([
    'int' => [4, '4'],
    'zero' => [0, '0'],
    'negative int' => [-2, '-2'],
    'float' => [4.5, '4.5'],
    'whole float' => [4.0, '4'],
    'quarter' => [8.75, '8.75'],
    'four places' => [9.9999, '9.9999'],
    'float noise' => [0.1 + 0.2, '0.3'],
    'float noise below' => [0.7 - 0.4, '0.3'],
    'negative zero float' => [-0.0, '0'],
    'string' => ['4', '4'],
    'decimal string' => ['4.50', '4.5'],
    'padded zeros' => ['004.5000', '4.5'],
    'plus sign' => ['+3', '3'],
    'negative string' => ['-1.25', '-1.25'],
    'negative zero string' => ['-0.000', '0'],
    'largest' => ['999999.9999', '999999.9999'],
    'smallest' => ['-999999.9999', '-999999.9999'],
    'largest float' => [999999.9999, '999999.9999'],
]);

test('float conversion ignores the locale and precision settings', function () {
    $locale = setlocale(LC_NUMERIC, '0');
    $precision = ini_get('precision');
    $serialize = ini_get('serialize_precision');

    try {
        setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8');
        ini_set('precision', '17');
        ini_set('serialize_precision', '17');

        $this->assertSame('4.5', RatingScale::normalize(4.5));
        $this->assertSame('0.1', RatingScale::normalize(0.1));
    } finally {
        setlocale(LC_NUMERIC, $locale ?: 'C');
        ini_set('precision', (string) $precision);
        ini_set('serialize_precision', (string) $serialize);
    }
});

test('invalid numbers are refused', function (mixed $value, string $message) {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage($message);

    RatingScale::normalize($value);
})->with([
    'null' => [null, 'not null'],
    'bool' => [false, 'not bool'],
    'array' => [[1], 'not array'],
    'NAN' => [NAN, 'finite'],
    '-INF' => [-INF, 'finite'],
    'empty' => ['', 'decimal number'],
    'space' => ['4 ', 'decimal number'],
    'exponent' => ['1e3', 'decimal number'],
    'comma' => ['4,5', 'decimal number'],
    'two dots' => ['4.5.1', 'decimal number'],
    'sign only' => ['-', 'decimal number'],
    'five places' => ['0.00001', 'more than 4 decimal places'],
    'five places float' => [0.00001, 'more than 4 decimal places'],
    'seven digits' => ['1000000', 'up to 6 whole digits'],
    'seven digits float' => [1000000.0, 'up to 6 whole digits'],
    'long excerpt' => [str_repeat('1', 100), '1111111111111111111111111111111111111111...'],
]);

test('the scale allows exactly min plus whole steps up to max', function () {
    $scale = RatingScale::fromArray(['min' => 0, 'max' => 10, 'step' => 0.5], 'test');

    foreach (['0', '0.5', '4.5', '9.5', '10'] as $value) {
        $this->assertTrue($scale->allows($value), $value);
    }

    foreach (['-0.5', '0.25', '4.75', '10.5', '0.0001'] as $value) {
        $this->assertFalse($scale->allows($value), $value);
    }
});

test('a scale with an offset min counts steps from min', function () {
    $scale = RatingScale::fromArray(['min' => '0.5', 'max' => '2.5', 'step' => 1], 'test');

    $this->assertTrue($scale->allows('0.5'));
    $this->assertTrue($scale->allows('1.5'));
    $this->assertTrue($scale->allows('2.5'));
    $this->assertFalse($scale->allows('1'));
    $this->assertFalse($scale->allows('2'));
});

test('a negative scale works', function () {
    $scale = RatingScale::fromArray(['min' => -2, 'max' => 2, 'step' => 1], 'test');

    $this->assertTrue($scale->allows('-2'));
    $this->assertTrue($scale->allows('0'));
    $this->assertFalse($scale->allows('-3'));
    $this->assertSame('-2 to 2 in steps of 1', $scale->describe());
    $this->assertSame(['min' => -2, 'max' => 2, 'step' => 1], $scale->toArray());
});

test('the scale describes itself and returns its numbers', function () {
    $scale = RatingScale::fromArray(['min' => '0.25', 'max' => 10, 'step' => '0.25'], 'test');

    $this->assertSame('0.25 to 10 in steps of 0.25', $scale->describe());
    $this->assertSame(['min' => 0.25, 'max' => 10, 'step' => 0.25], $scale->toArray());
});

test('invalid scales are refused with their source', function (mixed $scale, string $message) {
    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage($message);

    RatingScale::fromArray($scale, 'test');
})->with([
    'not an array' => ['1-5', 'test must be an array with min, max and step, not string'],
    'no min' => [['max' => 5, 'step' => 1], 'test must have a [min] key.'],
    'no step' => [['min' => 1, 'max' => 5], 'test must have a [step] key.'],
    'zero step' => [['min' => 1, 'max' => 5, 'step' => 0], 'test: the step must be greater than 0.'],
    'max equals min' => [['min' => 1, 'max' => 1, 'step' => 1], 'test: max must be greater than min.'],
    'max below min' => [['min' => 5, 'max' => 1, 'step' => 1], 'test: max must be greater than min.'],
    'uneven steps' => [['min' => 1, 'max' => 5, 'step' => 0.3], 'test: max - min must be a whole number of steps'],
    'invalid number' => [['min' => 'one', 'max' => 5, 'step' => 1], 'test: The min must be a decimal number such as 4 or 4.5, not [one].'],
    'null number' => [['min' => 1, 'max' => null, 'step' => 1], 'test: The max must be an integer, a float, or a decimal string, not null.'],
]);

test('values from the database normalize to four places', function (mixed $stored, ?string $canonical) {
    $this->assertSame($canonical, RatingScale::fromDatabase($stored));
})->with([
    'null' => [null, null],
    'mysql and pgsql decimal' => ['4.5000', '4.5'],
    'whole decimal' => ['5.0000', '5'],
    'sqlite integer' => [5, '5'],
    'sqlite real' => [4.5, '4.5'],
    'sqlite real sum' => [13.299999999999999, '13.3'],
    'mysql average' => ['4.33333333', '4.3333'],
    'pgsql average' => ['4.6666666666666667', '4.6667'],
    'negative' => ['-1.2500', '-1.25'],
]);

test('a stored value that is not a number is reported', function () {
    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage('The stored rating must be a decimal number');

    RatingScale::fromDatabase('abc');
});

test('numbers are ints when whole and floats otherwise', function () {
    $this->assertSame(5, RatingScale::toNumber('5'));
    $this->assertSame(-2, RatingScale::toNumber('-2'));
    $this->assertSame(4.5, RatingScale::toNumber('4.5'));
    $this->assertSame(0.0001, RatingScale::toNumber('0.0001'));
});
