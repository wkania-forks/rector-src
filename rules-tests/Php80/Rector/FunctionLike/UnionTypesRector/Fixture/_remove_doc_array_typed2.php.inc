<?php

namespace Rector\Tests\Php80\Rector\FunctionLike\UnionTypesRector\Fixture;

final class RemoveDocArrayTyped2
{
    /**
     * @param bool|float|int|string|array<mixed> $value
     */
    public function normalizeNodeValue($value)
    {
        return $value;
    }
}

?>
-----
<?php

namespace Rector\Tests\Php80\Rector\FunctionLike\UnionTypesRector\Fixture;

final class RemoveDocArrayTyped2
{
    public function normalizeNodeValue(bool|float|int|string|array $value)
    {
        return $value;
    }
}

?>
