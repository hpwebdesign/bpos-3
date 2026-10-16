<?php
namespace Bpos;

class Nav {
	private $registry;
	private $config;

	public function __construct($registry) {
		$this->registry = $registry;
		$this->config = $registry->get('config');
	}

	// Ready pages the role may open, in registry order
	public function items($role, Perm $perm, Response $out, $language) {
		$items = array();

		foreach ((array)$this->config->get('bpos_pages') as $key => $page) {
			if (empty($page['ready']) || !$perm->can($role, $page['cap'])) {
				continue;
			}

			$items[] = array(
				'key'   => $key,
				'route' => $page['route'],
				'icon'  => $page['icon'],
				'label' => $language->get('nav_' . $key),
				'href'  => $out->url($page['route'])
			);
		}

		return $items;
	}

	public function home($role, Perm $perm) {
		foreach ((array)$this->config->get('bpos_pages') as $page) {
			if (!empty($page['ready']) && $perm->can($role, $page['cap'])) {
				return $page['route'];
			}
		}

		return '';
	}
}
