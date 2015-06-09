<?php

/**
 * @file
 * Contains \Drupal\Tests\migrate\Unit\MigrateTestCase.
 */

namespace Drupal\Tests\migrate\Unit;

use Drupal\Core\Database\Driver\sqlite\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Provides setup and helper methods for Migrate module tests.
 */
abstract class MigrateTestCase extends UnitTestCase {

  protected $migrationConfiguration = [];

  /**
   * Retrieve a mocked migration.
   *
   * @return \Drupal\migrate\Entity\MigrationInterface|\PHPUnit_Framework_MockObject_MockObject
   *   The mocked migration.
   */
  protected function getMigration() {
    $this->migrationConfiguration += ['migrationClass' => 'Drupal\migrate\Entity\Migration'];
    $this->idMap = $this->getMock('Drupal\migrate\Plugin\MigrateIdMapInterface');

    $this->idMap->expects($this->any())
      ->method('getQualifiedMapTableName')
      ->will($this->returnValue('test_map'));

    $migration = $this->getMockBuilder($this->migrationConfiguration['migrationClass'])
      ->disableOriginalConstructor()
      ->getMock();
    $migration->expects($this->any())
      ->method('checkRequirements')
      ->will($this->returnValue(TRUE));
    $migration->expects($this->any())
      ->method('getIdMap')
      ->will($this->returnValue($this->idMap));
    $configuration = &$this->migrationConfiguration;
    $migration->expects($this->any())->method('get')->will($this->returnCallback(function ($argument) use (&$configuration) {
      return isset($configuration[$argument]) ? $configuration[$argument] : '';
    }));
    $migration->expects($this->any())->method('set')->will($this->returnCallback(function ($argument, $value) use (&$configuration) {
      $configuration[$argument] = $value;
    }));
    $migration->expects($this->any())
      ->method('id')
      ->will($this->returnValue($configuration['id']));
    return $migration;
  }

  /**
   * Get an SQLite database connection object for use in tests.
   *
   * @param array $database_contents
   *   The database contents faked as an array. Each key is a table name, each
   *   value is a list of table rows, an associative array of field => value.
   * @param array $connection_options
   *  (optional) Options for the database connection.
   *
   * @return \Drupal\Core\Database\Driver\sqlite\Connection
   *   The database connection.
   */
  protected function getDatabase(array $database_contents, $connection_options = []) {
    if (extension_loaded('pdo_sqlite')) {
      $connection_options['database'] = ':memory:';
      $pdo = Connection::open($connection_options);
      $connection = new Connection($pdo, $connection_options);
    }
    else {
      throw new \Exception('pdo_sqlite extension is required.');
    }

    // Initialize the DIC with a fake module handler for alterable queries.
    $container = new ContainerBuilder();
    $container->set('module_handler', $this->getMock('\Drupal\Core\Extension\ModuleHandlerInterface'));
    \Drupal::setContainer($container);

    // Create the tables and load them up with data, skipping empty ones.
    foreach (array_filter($database_contents) as $table => $rows) {
      $pilot_row = reset($rows);
      $connection->schema()->createTable($table, $this->createSchemaFromRow($pilot_row));

      $insert = $connection->insert($table)->fields(array_keys($pilot_row));
      array_walk($rows, [$insert, 'values']);
      $insert->execute();
    }

    return $connection;
  }

  /**
   * Generates a table schema from a row.
   *
   * @param array $row
   *  The reference row on which to base the schema.
   *
   * @return array
   *  The Schema API-ready table schema.
   */
  protected function createSchemaFromRow(array $row) {
    // SQLite uses loose ("affinity") typing, so it's OK for every column
    // to be a text field.
    $fields = array_map(function() { return ['type' => 'text']; }, $row);
    return ['fields' => $fields];
  }

  /**
   * Tests a query
   *
   * @param array|\Traversable
   *   The countable. foreach-able actual results if a query is being run.
   */
  public function queryResultTest($iter, $expected_results) {
    $this->assertSame(count($expected_results), count($iter), 'Number of results match');
    $count = 0;
    foreach ($iter as $data_row) {
      $expected_row = $expected_results[$count];
      $count++;
      foreach ($expected_row as $key => $expected_value) {
        $this->retrievalAssertHelper($expected_value, $this->getValue($data_row, $key), sprintf('Value matches for key "%s"', $key));
      }
    }
    $this->assertSame(count($expected_results), $count);
  }

  /**
   * @param array $row
   * @param string $key
   * @return mixed
   */
  protected function getValue($row, $key) {
    return $row[$key];
  }

  /**
   * Asserts tested values during test retrieval.
   *
   * @param mixed $expected_value
   *   The incoming expected value to test.
   * @param mixed $actual_value
   *   The incoming value itself.
   * @param string $message
   *   The tested result as a formatted string.
   */
  protected function retrievalAssertHelper($expected_value, $actual_value, $message) {
    if (is_array($expected_value)) {
      foreach ($expected_value as $k => $v) {
        $this->retrievalAssertHelper($v, $actual_value[$k], $message . '[' . $k . ']');
      }
    }
    else {
      $this->assertSame((string) $expected_value, (string) $actual_value, $message);
    }
  }

}
