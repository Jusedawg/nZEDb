<?php
use Migrations\AbstractMigration;

class CreateCouchpotato extends AbstractMigration
{
	/**
	 * Change Method.
	 *
	 * More information on this method is available here:
	 * http://docs.phinx.org/en/latest/migrations.html#the-change-method
	 * @return void
	 */
	public function change(): void
	{
		$table = $this->table('couchpotato', ['id' => false, 'primary_key' => ['user_id']])
			->addColumn('user_id', 'integer', [
				'comment' => 'FK to users.id',
				'default' => null,
				'limit' => 11,
				'null' => false,
			])
			->addColumn('url', 'string', [
				'comment' => 'url to CP server',
				'default' => null,
				'limit' => 255,
				'null' => false,
			])
			->addColumn('api', 'string', [
				'comment' => 'api key to access the server',
				'default' => null,
				'limit' => 255,
				'null' => false,
			])
			->addPrimaryKey(['user_id']);

		$table->create();
	}
}