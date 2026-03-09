<?php declare(strict_types = 0);

namespace Modules\ZabbixFinOpsToolkit;

use APP,
	CMenuItem,
	Zabbix\Core\CModule;

class Module extends CModule {

	public function init(): void {
		$menu = APP::Component()->get('menu.main');

		$monitoring_menu = $menu->find(_('Monitoring'));

		if ($monitoring_menu !== null) {
			$monitoring_menu->getSubMenu()->add(
				(new CMenuItem(_('Infrastructure Cost Analyzer')))
					->setAction('finops.costanalyzer.view')
			);
		}
	}
}
