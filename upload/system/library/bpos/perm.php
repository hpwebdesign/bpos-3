<?php
namespace Bpos;

class Perm {
	private $config;

	public function __construct($registry) {
		$this->config = $registry->get('config');
	}

	public function rank($role) {
		$rank = array_search((string)$role, (array)$this->config->get('bpos_roles'), true);

		return $rank === false ? -1 : (int)$rank;
	}

	public function isRole($role) {
		return $this->rank($role) >= 0;
	}

	// Page keys a role may open
	public function pages($role) {
		$all = array_keys((array)$this->config->get('bpos_pages'));

		if ($role === 'owner') {
			return $all;
		}

		$map = (array)$this->config->get('bpos_role_pages');

		return isset($map[$role]) ? array_values(array_intersect($all, $map[$role])) : array();
	}

	// '' means "any signed-in user"
	public function can($role, $cap) {
		$cap = (string)$cap;

		if (!$this->isRole($role)) {
			return false;
		}

		if ($cap === '' || $role === 'owner') {
			return true;
		}

		if (in_array($cap, $this->pages($role), true)) {
			return true;
		}

		$fixed = (array)$this->config->get('bpos_cap_fixed');

		if (isset($fixed[$cap])) {
			return $this->rank($role) >= $this->rank($fixed[$cap]);
		}

		$defaults = (array)$this->config->get('bpos_perm_default');

		if (isset($defaults[$cap])) {
			$stored = $this->config->get('module_bpos_perm');
			$matrix = is_array($stored) && isset($stored[$cap]) && is_array($stored[$cap]) ? $stored[$cap] : array();
			$matrix = array_merge($defaults[$cap], $matrix);

			return !empty($matrix[$role]);
		}

		return false;
	}

	// Every capability the role holds, for the client
	public function caps($role) {
		$names = array_merge(
			array_keys((array)$this->config->get('bpos_pages')),
			array_keys((array)$this->config->get('bpos_cap_fixed')),
			array_keys((array)$this->config->get('bpos_perm_default'))
		);

		$out = array();

		foreach ($names as $cap) {
			if ($this->can($role, $cap)) {
				$out[] = $cap;
			}
		}

		return $out;
	}
}
