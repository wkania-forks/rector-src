<?php

namespace Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\FixtureAutoImported;

use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

class User
{
    /**
     * @ORM\Column(type="string", length=27)
     * @Assert\NotBlank()
     * @Assert\Length(max=27)
     */
    private string $email;
}

?>
-----
<?php

namespace Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\FixtureAutoImported;

use Doctrine\ORM\Mapping\Column;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\ORM\Mapping as ORM;

class User
{
    /**
     * @Assert\NotBlank()
     * @Assert\Length(max=27)
     */
    #[Column(type: 'string', length: 27)]
    private string $email;
}

?>
