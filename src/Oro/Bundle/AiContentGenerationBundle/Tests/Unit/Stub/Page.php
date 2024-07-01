<?php

namespace Oro\Bundle\AiContentGenerationBundle\Tests\Unit\Stub;

use Oro\Bundle\CMSBundle\Tests\Unit\Entity\Stub\Page as BasePage;
use Oro\Bundle\SEOBundle\Tests\Unit\Entity\Stub\MetaFieldSetterGetterTrait;

class Page extends BasePage
{
    use MetaFieldSetterGetterTrait {
        MetaFieldSetterGetterTrait::__construct as private traitConstructor;
    }

    public function __construct()
    {
        parent::__construct();
        $this->traitConstructor();
    }
}
