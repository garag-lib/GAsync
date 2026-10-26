<?php

declare(strict_types=1);

use GaragLib\GResourceStorage;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    public function testSetsAndArrayAccessPreserveResourceIdentity(): void
    {
        $a = tmpfile();
        $b = tmpfile();
        $c = tmpfile();
        try {
            $storage = new GResourceStorage();
            $storage[$a] = 'a';
            $storage[$b] = 'b';
            $storage[$c] = null;
            self::assertTrue(isset($storage[$c]));
            self::assertSame('a', $storage[get_resource_id($a)]);
            self::assertSame(1, $storage->removeAll([$b]));
            self::assertSame(1, $storage->removeAllExcept([$c]));
            self::assertSame([$c], array_values(iterator_to_array($storage)));
            fclose($c);
            $storage->detach($c);
            self::assertCount(0, $storage);
        } finally {
            fclose($a);
            fclose($b);
            if (is_resource($c)) {
                fclose($c);
            }
        }
    }

    public function testSerializationIsRejected(): void
    {
        $this->expectException(LogicException::class);
        serialize(new GResourceStorage());
    }
}
