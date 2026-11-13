<?php

namespace Pharaonic\Laravel\Ratings\Tests\Unit;

use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingManager;
use PHPUnit\Framework\TestCase;

class CommentTest extends TestCase
{
    public function test_a_comment_is_kept_exactly_as_given(): void
    {
        foreach (['Nice', '  Nice  ', "Line one\nLine two", 'Ölçü ✓ 😀', '0', str_repeat('a', 65535)] as $comment) {
            $this->assertSame($comment, RatingManager::comment($comment));
        }
    }

    public function test_null_and_blank_strings_mean_no_comment(): void
    {
        foreach ([null, '', ' ', "\n\t ", "\r\n"] as $comment) {
            $this->assertNull(RatingManager::comment($comment), var_export($comment, true));
        }
    }

    public function test_a_comment_that_is_not_a_string_is_refused(): void
    {
        foreach (['int' => 42, 'float' => 4.5, 'bool' => true, 'array' => ['Nice']] as $type => $value) {
            try {
                RatingManager::comment($value);
                $this->fail("A {$type} comment was accepted.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame("The comment must be a string or null, not {$type}.", $exception->getMessage());
            }
        }
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The comment must be valid UTF-8.');

        RatingManager::comment("Nice \xC3\x28");
    }

    public function test_a_comment_over_65535_bytes_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The comment is 65536 bytes long; a comment may have up to 65535 bytes.');

        // 32,768 two-byte characters.
        RatingManager::comment(str_repeat('é', 32768));
    }

    public function test_the_model_applies_the_same_rules(): void
    {
        $rating = new Rating;

        $rating->comment = '  ';
        $this->assertNull($rating->comment);

        $rating->comment = ' Kept ';
        $this->assertSame(' Kept ', $rating->comment);

        $rating->fill(['comment' => '']);
        $this->assertNull($rating->comment);

        $this->expectException(InvalidArgumentException::class);
        $rating->fill(['comment' => 5]);
    }
}
