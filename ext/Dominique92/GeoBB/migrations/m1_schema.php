<?php
/**
*
* Spacial objects extension for the phpBB Forum Software package.
*
* @copyright (c) 2016 Dominique Cavailhez
* @license GNU General Public License, version 2 (GPL-2.0)
*
*/

namespace Dominique92\GeoBB\migrations;

/**
 * Migration stage 1: Schema changes
 */
class m1_schema extends \phpbb\db\migration\migration
{
	/**
	 * Check if this migration is effectively installed
	 *
	 * @return bool True if this migration is installed, False if this migration is not installed
	 * @access public
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'posts', 'geom');
	}

	/**
	 * Add the specific columns to the posts table
	 *
	 * @return array Array of table schema
	 * @access public
	 */
	public function update_schema()
	{
		return array(
			'add_columns'	=> array(
				$this->table_prefix . 'posts'	=> array(
					'geom' => array('TEXT'), // Will be transformed into GEOMETRYCOLLECTION by a lister hack
					'geo_altitude' => array('UINT'),
					'geo_massif' => array('VCHAR:50'),
				),
			),
		);
	}

	/**
	 * Drop the specific columns from the posts table
	 *
	 * @return array Array of table schema
	 * @access public
	 */
	public function revert_schema()
	{
		return array(
			'drop_columns'	=> array(
				$this->table_prefix . 'posts'	=> array(
					'geom',
					'geo_altitude',
					'geo_massif',
				),
			),
		);
	}
}
