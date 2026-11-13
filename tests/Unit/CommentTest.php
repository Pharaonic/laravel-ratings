<?php

use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingManager;

test('a comment is kept exactly as given', function () {
    foreach (['Nice', '  Nice  ', "Line one\nLine two", 'Ölçü ✓ 😀', '0', str_repeat('a', 65535)] as $comment) {
        $this->assertSame($comment, RatingManager::comment($comment));
    }
});

test('null and blank strings mean no comment', function () {
    foreach ([null, '', ' ', "\n\t ", "\r\n"] as $comment) {
        $this->assertNull(RatingManager::comment($comment), var_export($comment, true));
    }
});

test('a comment that is not a string is refused', function () {
    foreach (['int' => 42, 'float' => 4.5, 'bool' => true, 'array' => ['Nice']] as $type => $value) {
        try {
            RatingManager::comment($value);
            $this->fail("A {$type} comment was accepted.");
        } catch (InvalidArgumentException $exception) {
            $this->assertSame("The comment must be a string or null, not {$type}.", $exception->getMessage());
        }
    }
});

test('invalid utf8 is refused', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('The comment must be valid UTF-8.');

    RatingManager::comment("Nice \xC3\x28");
});

test('a comment over 65535 bytes is refused', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('The comment is 65536 bytes long; a comment may have up to 65535 bytes.');

    // 32,768 two-byte characters.
    RatingManager::comment(str_repeat('é', 32768));
});

test('the model applies the same rules', function () {
    $rating = new Rating;

    $rating->comment = '  ';
    $this->assertNull($rating->comment);

    $rating->comment = ' Kept ';
    $this->assertSame(' Kept ', $rating->comment);

    $rating->fill(['comment' => '']);
    $this->assertNull($rating->comment);

    $this->expectException(InvalidArgumentException::class);
    $rating->fill(['comment' => 5]);
});
