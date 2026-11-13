<?php

namespace Pharaonic\Laravel\Ratings\Tests\Unit;

use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingResult;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RatingResultTest extends TestCase
{
    public function test_a_created_result(): void
    {
        $rating = new Rating;
        $result = new RatingResult('created', $rating, null, 5);

        $this->assertTrue($result->created());
        $this->assertFalse($result->updated());
        $this->assertFalse($result->unchanged());
        $this->assertTrue($result->changed());
        $this->assertSame('created', $result->status());
        $this->assertNull($result->previous());
        $this->assertSame(5, $result->current());
        $this->assertSame($rating, $result->rating());
    }

    public function test_an_updated_result(): void
    {
        $result = new RatingResult('updated', new Rating, 4.5, 3);

        $this->assertFalse($result->created());
        $this->assertTrue($result->updated());
        $this->assertFalse($result->unchanged());
        $this->assertTrue($result->changed());
        $this->assertSame(4.5, $result->previous());
        $this->assertSame(3, $result->current());
    }

    public function test_an_unchanged_result(): void
    {
        $result = new RatingResult('unchanged', new Rating, 4, 4);

        $this->assertFalse($result->created());
        $this->assertFalse($result->updated());
        $this->assertTrue($result->unchanged());
        $this->assertFalse($result->changed());
        $this->assertSame(4, $result->previous());
        $this->assertSame(4, $result->current());
    }

    public function test_the_result_is_a_final_snapshot_without_mutators(): void
    {
        $class = new ReflectionClass(RatingResult::class);

        $this->assertTrue($class->isFinal());

        foreach ($class->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), $property->getName());
        }

        $methods = array_map(function ($method) {
            return $method->getName();
        }, $class->getMethods());
        sort($methods);

        $this->assertSame(
            ['__construct', 'changed', 'created', 'current', 'previous', 'rating', 'status', 'unchanged', 'updated'],
            $methods
        );
    }

    public function test_changing_the_rating_later_does_not_change_the_result(): void
    {
        $rating = new Rating;
        $rating->setRawAttributes(['value' => '4']);
        $result = new RatingResult('updated', $rating, 2, 4);

        $rating->setRawAttributes(['value' => '1']);

        $this->assertSame(4, $result->current());
        $this->assertSame(2, $result->previous());
        $this->assertTrue($result->updated());
    }
}
