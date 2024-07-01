<?php

namespace Oro\Bundle\AiContentGenerationBundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class OroAiContentGenerationBundleInstaller implements Installation
{
    /**
     * {@inheritdoc}
     */
    public function getMigrationVersion(): string
    {
        return 'v1_0';
    }

    /**
     * {@inheritdoc}
     */
    public function up(Schema $schema, QueryBag $queries): void
    {
        $this->createOroOpenAiIntegrationTransport($schema);
        $this->createOroOpenAiTransportLabelTable($schema);
        $this->addOroOpenAiTransportLabelForeignKeys($schema);

        $this->createOroVertexAiIntegrationTransport($schema);
        $this->createOroVertexAiTransportLabelTable($schema);
        $this->addOroVertexAiTransportLabelForeignKeys($schema);
    }

    private function createOroOpenAiIntegrationTransport(Schema $schema): void
    {
        $table = $schema->getTable('oro_integration_transport');
        $table->addColumn('open_ai_model', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('open_ai_token', 'crypted_string', [
            'notnull' => false,
            'length' => 255,
            'comment' => '(DC2Type:crypted_string)'
        ]);
    }

    private function createOroOpenAiTransportLabelTable(Schema $schema): void
    {
        if (!$schema->hasTable('oro_open_ai_transp_label')) {
            $table = $schema->createTable('oro_open_ai_transp_label');
            $table->addColumn('transport_id', 'integer');
            $table->addColumn('localized_value_id', 'integer');
            $table->setPrimaryKey(['transport_id', 'localized_value_id']);
            $table->addUniqueIndex(['localized_value_id'], 'oro_open_ai_transp_label_localized_value_id');
            $table->addIndex(['transport_id'], 'oro_open_ai_transp_label_transport_id');
        }
    }

    private function addOroOpenAiTransportLabelForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable('oro_open_ai_transp_label');
        if (!$table->hasForeignKey('transport_id')) {
            $table->addForeignKeyConstraint(
                $schema->getTable('oro_integration_transport'),
                ['transport_id'],
                ['id'],
                ['onUpdate' => null, 'onDelete' => 'CASCADE']
            );
        }

        if (!$table->hasForeignKey('localized_value_id')) {
            $table->addForeignKeyConstraint(
                $schema->getTable('oro_fallback_localization_val'),
                ['localized_value_id'],
                ['id'],
                ['onUpdate' => null, 'onDelete' => 'CASCADE']
            );
        }
    }

    private function createOroVertexAiIntegrationTransport(Schema $schema): void
    {
        $table = $schema->getTable('oro_integration_transport');
        $table->addColumn('vertex_ai_api_endpoint', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('vertex_ai_project_id', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('vertex_ai_location', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('vertex_ai_model', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('vertex_ai_config_file', 'crypted_text', [
            'notnull' => false,
            'comment' => '(DC2Type:crypted_text)'
        ]);
    }

    private function createOroVertexAiTransportLabelTable(Schema $schema): void
    {
        if (!$schema->hasTable('oro_vertex_ai_transp_label')) {
            $table = $schema->createTable('oro_vertex_ai_transp_label');
            $table->addColumn('transport_id', 'integer');
            $table->addColumn('localized_value_id', 'integer');
            $table->setPrimaryKey(['transport_id', 'localized_value_id']);
            $table->addUniqueIndex(['localized_value_id'], 'oro_vertex_ai_transp_label_localized_value_id');
            $table->addIndex(['transport_id'], 'oro_vertex_ai_transp_label_transport_id');
        }
    }

    private function addOroVertexAiTransportLabelForeignKeys(Schema $schema): void
    {
        $table = $schema->getTable('oro_vertex_ai_transp_label');
        if (!$table->hasForeignKey('transport_id')) {
            $table->addForeignKeyConstraint(
                $schema->getTable('oro_integration_transport'),
                ['transport_id'],
                ['id'],
                ['onUpdate' => null, 'onDelete' => 'CASCADE']
            );
        }

        if (!$table->hasForeignKey('localized_value_id')) {
            $table->addForeignKeyConstraint(
                $schema->getTable('oro_fallback_localization_val'),
                ['localized_value_id'],
                ['id'],
                ['onUpdate' => null, 'onDelete' => 'CASCADE']
            );
        }
    }
}
