<?php

namespace Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Fixture;

use Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Source\DummyUserProfile;
use Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Source\DummyUserProvider;

class SomeClass
{
    public function run(DummyUserProvider $dummyUserProvider, $oldDescription)
    {
        $user = $dummyUserProvider->getDummyUser();

        $this->someMethod(
            (new DummyUserProfile())
                ->setOldDescription($oldDescription)
                ->setUserId($user->id)
        );
    }

    public function someMethod(DummyUserProfile $dummyUserProfile)
    {
    }
}

?>
-----
<?php

namespace Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Fixture;

use Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Source\DummyUserProfile;
use Rector\Tests\Defluent\Rector\MethodCall\InArgFluentChainMethodCallToStandaloneMethodCallRector\Source\DummyUserProvider;

class SomeClass
{
    public function run(DummyUserProvider $dummyUserProvider, $oldDescription)
    {
        $user = $dummyUserProvider->getDummyUser();
        $dummyUserProfile = new DummyUserProfile();
        ($dummyUserProfile)
            ->setOldDescription($oldDescription);
        $dummyUserProfile
            ->setUserId($user->id);

        $this->someMethod(
            $dummyUserProfile
        );
    }

    public function someMethod(DummyUserProfile $dummyUserProfile)
    {
    }
}

?>
