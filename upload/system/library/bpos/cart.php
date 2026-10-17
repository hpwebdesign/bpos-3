<?php
namespace Bpos;

// Minimal in-memory stand-in for Cart\Cart. Swapped into the registry while shipping extensions quote rates.
class Cart {
	private $products;
	private $sub_total;

	public function __construct(array $products, $sub_total) {
		$this->products = $products;
		$this->sub_total = (float)$sub_total;
	}

	public function getProducts() {
		return $this->products;
	}

	public function getSubTotal() {
		return $this->sub_total;
	}

	public function getTotal() {
		return $this->sub_total;
	}

	public function getTaxes() {
		return array();
	}

	public function getWeight() {
		$weight = 0;

		foreach ($this->products as $product) {
			if ($product['shipping']) {
				$weight += $product['weight'];
			}
		}

		return $weight;
	}

	public function countProducts() {
		$total = 0;

		foreach ($this->products as $product) {
			$total += $product['quantity'];
		}

		return $total;
	}

	public function hasProducts() {
		return (bool)$this->products;
	}

	public function hasShipping() {
		foreach ($this->products as $product) {
			if ($product['shipping']) {
				return true;
			}
		}

		return false;
	}

	public function hasStock() {
		return true;
	}

	public function hasRecurringProducts() {
		return false;
	}

	public function getRecurringProducts() {
		return array();
	}

	public function hasDownload() {
		return false;
	}
}
