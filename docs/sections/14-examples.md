## Examples

### 1. Rate a product from a form

The controller validates the request against the product's own scale, then reports what happened.

- ===Controller

  ```php title="app/Http/Controllers/ProductRatingController.php"
  namespace App\Http\Controllers;

  use App\Product;
  use Illuminate\Http\Request;

  class ProductRatingController extends Controller
  {
      public function store(Request $request, Product $product)
      {
          $scale = $product->ratingScale();

          $data = $request->validate([
              'value' => ['required', 'numeric', 'min:'.$scale['min'], 'max:'.$scale['max']],
              'comment' => ['nullable', 'string', 'max:5000'],
          ]);

          $result = $request->user()->rate($product, $data['value'], $data['comment'] ?? null);

          return back()->with('status', $result->created() ? 'Thanks for rating!' : 'Your rating was updated.');
      }

      public function destroy(Request $request, Product $product)
      {
          $request->user()->unrate($product);

          return back();
      }
  }
  ```

- ===Routes

  ```php title="routes/web.php"
  Route::post('products/{product}/rating', 'ProductRatingController@store')->middleware('auth');
  Route::delete('products/{product}/rating', 'ProductRatingController@destroy')->middleware('auth');
  ```

The form always sends a comment field, so an empty textarea clears the comment. To change only the stars, call `rate()` without the third argument.

### 2. A product page with the rating summary

```php title="app/Http/Controllers/ProductController.php"
public function show(Product $product)
{
    return view('products.show', [
        'product' => $product,
        'average' => $product->averageRating(),
        'count' => $product->ratingsCount(),
        'distribution' => $product->ratingDistribution(),
        'mine' => auth()->user() ? auth()->user()->ratingFor($product) : null,
        'reviews' => $product->ratingsWithComments()->with('rater')->latest()->paginate(10),
    ]);
}
```

```blade title="resources/views/products/show.blade.php"
@if ($average !== null)
    <p>{{ number_format($average, 1) }} out of 5 ({{ $count }} ratings)</p>
@endif

@foreach ([5, 4, 3, 2, 1] as $stars)
    <p>{{ $stars }} stars: {{ $distribution[$stars] ?? 0 }}</p>
@endforeach

@foreach ($reviews as $review)
    <blockquote>{{ $review->comment }} ({{ $review->value }}/5, {{ $review->rater->name }})</blockquote>
@endforeach
```

### 3. Edit only the comment

The rating is an Eloquent model, so an edit form updates it directly:

```php title="app/Http/Controllers/ReviewController.php"
public function update(Request $request, Product $product)
{
    $rating = $request->user()->ratingFor($product);

    abort_if($rating === null, 404);

    $rating->update($request->validate(['comment' => ['nullable', 'string', 'max:5000']]));

    return back();
}
```

### 4. Half-star course ratings

```php title="app/Course.php"
class Course extends Model
{
    use HasRatings;

    public function ratingScale(): array
    {
        return ['min' => 0.5, 'max' => 5, 'step' => 0.5];
    }
}
```

```php
$user->rate($course, 4.5);
$course->averageRating();         // 4.5
Course::whereRatingAtLeast(4)->orderBy('title')->paginate();
```

### 5. Top-rated products with their averages

```php title="app/Http/Controllers/TopProductsController.php"
use Illuminate\Support\Facades\DB;

$products = Product::whereRatingAtLeast(4)
    ->withCount([
        'ratings',
        'ratings as ratings_avg_value' => function ($query) {
            $query->select(DB::raw('avg(value)'));
        },
    ])
    ->orderByDesc('ratings_avg_value')
    ->take(10)
    ->get();
```
