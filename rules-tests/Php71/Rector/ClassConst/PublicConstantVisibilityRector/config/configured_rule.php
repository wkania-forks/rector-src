<?php

declare(strict_types=1);

use Rector\Php71\Rector\ClassConst\PublicConstantVisibilityRector;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();
    $services->set(PublicConstantVisibilityRector::class);
};
