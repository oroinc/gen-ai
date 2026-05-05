<?php

namespace Oro\Bundle\AiContentGenerationBundle\Migrations\Schema\v1_0_1;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Oro\Bundle\MigrationBundle\Migration\Migration;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;
use Oro\Bundle\SecurityBundle\DoctrineExtension\Dbal\Types\CryptedTextType;

/**
 * Converts OpenAI `token` column from crypted_string (VARCHAR(255)) to crypted_text (TEXT) to fit AES-encrypted values
 * that exceed 255 chars.
 */
class ChangeEncryptedColumnsToCryptedText implements Migration
{
    #[\Override]
    public function up(Schema $schema, QueryBag $queries): void
    {
        $table = $schema->getTable('oro_integration_transport');

        if ($table->getColumn('open_ai_token')->getType()->getName() === CryptedTextType::TYPE) {
            return;
        }

        $table->modifyColumn('open_ai_token', [
            'type' => Type::getType(CryptedTextType::TYPE),
            'length' => null,
            'comment' => '(DC2Type:' . CryptedTextType::TYPE . ')',
        ]);
    }
}
