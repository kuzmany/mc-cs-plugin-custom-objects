<?php

declare(strict_types=1);

/*
* @copyright   2019 Mautic, Inc. All rights reserved
* @author      Mautic, Inc.
*
* @link        https://mautic.com
*
* @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace MauticPlugin\CustomObjectsBundle\Migrations;

use MauticPlugin\CustomObjectsBundle\Migration\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaException;

class Version_0_0_1 extends AbstractMigration
{
    /**
     * @var string
     */
    private $table = 'custom_object';

    /**
     * {@inheritdoc}
     */
    protected function isApplicable(Schema $schema): bool
    {
        try {
            return !$schema->getTable($this->concatPrefix($this->table))->hasColumn('description');
        } catch (SchemaException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function up(): void
    {
        $this->addSql("
            ALTER TABLE `{$this->concatPrefix($this->table)}`
            ADD `description` varchar(255) NULL AFTER `name_singular`
        ");
    }
}
