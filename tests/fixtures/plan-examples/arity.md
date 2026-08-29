```php
<?php
namespace PostDomain\Fixture;

final class Caller {
	public function go( object $lease ): bool {
		return $lease->finalize( 1, 2, 3 );
	}
}
```
