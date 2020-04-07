<?php
/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

require_once 'vendor/autoload.php';

require_once dirname(__FILE__).'/../../include/CWebTest.php';
require_once dirname(__FILE__).'/../traits/MacrosTrait.php';

/**
 * Base class for Macros tests.
 */
abstract class testFormMacros extends CWebTest {

	use MacrosTrait;

	const SQL_HOSTS = 'SELECT * FROM hosts ORDER BY hostid';

	public static function getHash() {
		return CDBHelper::getHash(self::SQL_HOSTS);
	}

	/**
	 * Test creating of host or template with Macros.
	 */
	protected function checkCreate($data, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		if ($is_prototype) {
			$this->page->login()->open('host_prototypes.php?form=create&parent_discoveryid='.$lld_id);
		}
		else {
			$this->page->login()->open($host_type.'s.php?form=create');
		}

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->fill([ucfirst($host_type).' name' => $data['Name']]);

		if ($is_prototype) {
			$form->selectTab('Groups');
		}
		$form->fill(['Groups' => 'Zabbix servers']);

		$name =  $data['Name'];
		$this->checkMacros($data, $form_type, $name, $host_type, $is_prototype, $lld_id);
	}

	/**
	 * Test updating of host or template with Macros.
	 */
	protected function checkUpdate($data, $hostname, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($hostname));

		if ($is_prototype) {
			$this->page->login()->open('host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id);
		}
		else {
			$this->page->login()->open($host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0');
		}
		$name = $hostname;
		$this->checkMacros($data, $form_type, $name, $host_type, $is_prototype, $lld_id);
	}

	/**
	 * Test removing Macros from host or template.
	 */
	protected function checkRemove($hostname, $form_type, $host_type, $is_prototype = false, $lld_id = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($hostname));

		if ($is_prototype) {
			$this->page->login()->open('host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id);
		}
		else {
			$this->page->login()->open($host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0');
		}


		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->removeMacros();
		$form->submit();

		$message = CMessageElement::find()->one();
		$this->assertTrue($message->isGood());

		if ($is_prototype) {
			$this->assertEquals('Host prototype updated', $message->getTitle());
		}
		else {
			$this->assertEquals(ucfirst($host_type).' updated', $message->getTitle());
		}

		$this->assertEquals(1, CDBHelper::getCount('SELECT NULL FROM hosts WHERE host='.zbx_dbstr($hostname)));
		// Check the results in form.
		$this->checkMacrosFields($hostname, $is_prototype, $lld_id, $host_type, $form_type,  null);
	}

	/**
	 * Test changing and resetting global macro on host, prototype or template.
	 */
	protected function checkChangeRemoveInheritedMacro($form_type, $host_type, $is_prototype = false, $lld_id = null) {
		if ($is_prototype) {
			$this->page->login()->open('host_prototypes.php?form=create&parent_discoveryid='.$lld_id);
			$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();

			$name = 'Host prototype with edited global {#MACRO}';
			$form->fill([ucfirst($host_type).' name' => $name]);
			$form->selectTab('Groups');
			$form->fill(['Groups' => 'Zabbix servers']);
		}
		else {
			$this->page->login()->open($host_type.'s.php?form=create');
			$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();

			$name = $host_type.' with edited global macro';
			$form->fill([
				ucfirst($host_type).' name' => $name,
				'Groups' => 'Zabbix servers'
			]);
		}
		$form->selectTab('Macros');
		// Go to inherited macros.
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		// Check inherited macros before changes.
		$this->checkInheritedGlobalMacros();

		$edited_macros = [
			[
				'macro' => '{$1}',
				'value' => 'New updated Numeric macro 1',
				'description' => 'New updated Test description 2'
			]
		];

		$count = count($edited_macros);
		// Change macro to edited values.
		for ($i = 0; $i < $count; $i += 1) {
			$this->query('id:macros_'.$i.'_change')->one()->click();
			$this->query('id:macros_'.$i.'_value')->one()->fill($edited_macros[$i]['value']);
			$this->query('id:macros_'.$i.'_description')->one()->fill($edited_macros[$i]['description']);
		}

		$form->submit();

		// Check saved edited macros in host/template form.
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));
		if ($is_prototype) {
			$this->page->open('host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id);
		}
		else {
			$this->page->open($host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0');
		}
		$form->selectTab('Macros');
		$this->assertMacros($edited_macros);

		// Remove edited macro and reset to global.
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		for ($i = 0; $i < $count; $i += 1) {
			$this->query('id:macros_'.$i.'_change')->waitUntilVisible()->one()->click();
		}
		$form->submit();

		if ($is_prototype) {
			$this->page->open('host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id);
		}
		else {
			$this->page->open($host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0');
		}

		$form->selectTab('Macros');
		$this->assertMacros();

		// Check inherited macros again after remove.
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		$this->checkInheritedGlobalMacros();
	}

	/**
	 * Check adding and saving macros in host or template form.
	 */
	private function checkMacros($data = null, $form_type, $name, $host_type, $is_prototype, $lld_id) {

		if ($data['expected'] === TEST_BAD) {
			$old_hash = $this->getHash();
		}

		$form = $this->query('name:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->fillMacros($data['macros']);
		$form->submit();

		$message = CMessageElement::find()->one();
		switch ($data['expected']) {
			case TEST_GOOD:
				$this->assertTrue($message->isGood());
				$this->assertEquals($data['success_message'], $message->getTitle());
				$this->assertEquals(1, CDBHelper::getCount('SELECT NULL FROM hosts WHERE host='.zbx_dbstr($name)));
				// Check the results in form.
				$this->checkMacrosFields($name, $is_prototype, $lld_id, $host_type, $form_type, $data);
				break;
			case TEST_BAD:
				$this->assertTrue($message->isBad());
				$this->assertEquals($data['error_message'], $message->getTitle());
				$this->assertTrue($message->hasLine($data['error_details']));
				// Check that DB hash is not changed.
				$this->assertEquals($old_hash, CDBHelper::getHash(self::SQL_HOSTS));
				break;
		}
	}

	/**
	 * Checking saved macros in host or template form.
	 */
	private function checkMacrosFields($name, $is_prototype, $lld_id, $host_type, $form_type,  $data = null) {
		$id = CDBHelper::getValue('SELECT hostid FROM hosts WHERE host='.zbx_dbstr($name));

		if ($is_prototype) {
			$this->page->open('host_prototypes.php?form=update&parent_discoveryid='.$lld_id.'&hostid='.$id);
		}
		else {
			$this->page->open($host_type.'s.php?form=update&'.$host_type.'id='.$id.'&groupid=0');
		}

		$form = $this->query('id:'.$form_type.'Form')->waitUntilPresent()->asForm()->one();
		$form->selectTab('Macros');
		$this->assertMacros(($data !== null) ? $data['macros'] : []);
		$this->query('xpath://label[@for="show_inherited_macros_1"]')->waitUntilPresent()->one()->click();
		// Get all macros defined for this host.
		$hostmacros = CDBHelper::getAll('SELECT macro, value, description FROM hostmacro where hostid ='.$id);

		$this->checkInheritedGlobalMacros($hostmacros);
	}

	/**
	 * Check host/template inherited macros in form matching with global macros in DB,
	 * if there is no any host/template defined macros.
	 */
	public function checkInheritedGlobalMacros($hostmacros = []) {
		// Create two macros arrays: from DB and from Frontend form.
		$macros = [
			// Merge global macros with host defined macros.
			'database' => array_merge(
					CDBHelper::getAll('SELECT macro, value, description FROM globalmacro'),
					$hostmacros
				),
			'frontend' => []
		];

		// Write macros rows from Frontend to array.
		$table = $this->query('id:tbl_macros')->waitUntilVisible()->asTable()->one();
		$count = $table->getRows()->count() - 1;
		for ($i = 0; $i < $count; $i += 2) {
			$macro = [];
			$row = $table->getRow($i);
			$macro['macro'] = $row->query('xpath:./td[1]/textarea')->one()->getValue();
			$macro['value'] = $row->query('xpath:./td[2]/div/textarea')->one()->getValue();
			$macro['description'] = $table->getRow($i + 1)->query('tag:textarea')->one()->getValue();

			$macros['frontend'][] = $macro;
		}

		// Sort arrays by Macros.
		foreach ($macros as &$array) {
			usort($array, function ($a, $b) {
				return strcmp($a['macro'], $b['macro']);
			});
		}
		unset($array);

		// Compare macros from DB with macros from Frontend.
		$this->assertEquals($macros['database'], $macros['frontend']);
	}
}
