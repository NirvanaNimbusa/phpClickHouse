<?php

use PHPUnit\Framework\TestCase;

/**
 * Class ClientTest
 */
class ClientTest extends TestCase
{
    /**
     * @var \ClickHouseDB\Client
     */
    private $db;

    /**
     * @var
     */
    private $tmp_path;

    /**
     * @throws Exception
     */
    public function setUp()
    {
        date_default_timezone_set('Europe/Moscow');

        if (!defined('phpunit_clickhouse_host')) {
            throw new Exception("Not set phpunit_clickhouse_host in phpUnit.xml");
        }

        $tmp_path = rtrim(phpunit_clickhouse_tmp_path, '/') . '/';

        if (!is_dir($tmp_path)) {
            throw  new Exception("Not dir in phpunit_clickhouse_tmp_path");
        }

        $this->tmp_path = $tmp_path;

        $config = [
            'host'     => phpunit_clickhouse_host,
            'port'     => phpunit_clickhouse_port,
            'username' => phpunit_clickhouse_user,
            'password' => phpunit_clickhouse_pass
        ];

        $this->db = new ClickHouseDB\Client($config);
        $this->db->ping();
    }

    /**
     *
     */
    public function tearDown()
    {
        //
    }

    /**
     * @return \ClickHouseDB\Statement
     */
    private function insert_data_table_summing_url_views()
    {
        return $this->db->insert(
            'summing_url_views',
            [
                [strtotime('2010-10-10 00:00:00'), 'HASH1', 2345, 22, 20, 2],
                [strtotime('2010-10-11 01:00:00'), 'HASH2', 2345, 12, 9, 3],
                [strtotime('2010-10-12 02:00:00'), 'HASH3', 5345, 33, 33, 0],
                [strtotime('2010-10-13 03:00:00'), 'HASH4', 5345, 55, 12, 55],
            ],
            ['event_time', 'url_hash', 'site_id', 'views', 'v_00', 'v_55']
        );
    }

    /**
     * @param $file_name
     * @param int $size
     */
    private function create_fake_csv_file($file_name, $size = 1)
    {
        if (is_file($file_name)) {
            unlink($file_name);
        }

        $handle = fopen($file_name, 'w');

        $z = 0;
        $rows = 0;

        for ($dates = 0; $dates < $size; $dates++) {
            for ($site_id = 10; $site_id < 99; $site_id++) {
                for ($hours = 0; $hours < 12; $hours++) {
                    $z++;

                    $dt = strtotime('-' . $dates . ' day');
                    $dt = strtotime('-' . $hours . ' hour', $dt);

                    $j = [];
                    $j['event_time'] = date('Y-m-d H:00:00', $dt);
                    $j['url_hash'] = 'x' . $site_id . 'x' . $size;
                    $j['site_id'] = $site_id;
                    $j['views'] = 1;

                    foreach (['00', 55] as $key) {
                        $z++;
                        $j['v_' . $key] = ($z % 2 ? 1 : 0);
                    }

                    fputcsv($handle, $j);
                    $rows++;
                }
            }
        }

        fclose($handle);
    }

    /**
     * @return \ClickHouseDB\Statement
     */
    private function create_table_summing_url_views()
    {
        $this->db->write("DROP TABLE IF EXISTS summing_url_views");

        return $this->db->write('
            CREATE TABLE IF NOT EXISTS summing_url_views (
                event_date Date DEFAULT toDate(event_time),
                event_time DateTime,
                url_hash String,
                site_id Int32,
                views Int32,
                v_00 Int32,
                v_55 Int32
            ) ENGINE = SummingMergeTree(event_date, (site_id, url_hash, event_time, event_date), 8192)
        ');
    }

    /**
     *
     */
    public function testGzipInsert()
    {
        $file_data_names = [
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.1.data',
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.2.data',
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.3.data',
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.4.data'
        ];

        foreach ($file_data_names as $file_name) {
            $this->create_fake_csv_file($file_name, 2);
        }

        $this->create_table_summing_url_views();

        $stat = $this->db->insertBatchFiles('summing_url_views', $file_data_names, [
            'event_time', 'url_hash', 'site_id', 'views', 'v_00', 'v_55'
        ]);

        $st = $this->db->select('SELECT sum(views) as sum_x,min(v_00) as min_x FROM summing_url_views');
        $this->assertEquals(8544, $st->fetchOne('sum_x'));

        $st = $this->db->select('SELECT * FROM summing_url_views ORDER BY url_hash');
        $this->assertEquals(8544, $st->count());

        // --- drop
        foreach ($file_data_names as $file_name) {
            unlink($file_name);
        }
    }

    /**
     * @expectedException \ClickHouseDB\DatabaseException
     */
    public function testInsertCSVError()
    {
        $file_data_names = [
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.1.data'
        ];

        foreach ($file_data_names as $file_name) {
            $this->create_fake_csv_file($file_name, 2);
        }

        $this->create_table_summing_url_views();
        $this->db->enableHttpCompression(true);

        $stat = $this->db->insertBatchFiles('summing_url_views', $file_data_names, [
            'event_time', 'site_id', 'views', 'v_00', 'v_55'
        ]);

        // --- drop
        foreach ($file_data_names as $file_name) {
            unlink($file_name);
        }
    }

    /**
     * @param $file_name
     * @param $array
     */
    private function make_csv_SelectWhereIn($file_name, $array)
    {
        if (is_file($file_name)) {
            unlink($file_name);
        }

        $handle = fopen($file_name, 'w');
        foreach ($array as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     *
     */
    public function testSelectWhereIn()
    {
        $file_data_names = [
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.1.data'
        ];

        $file_name_where_in1 = $this->tmp_path . '_testSelectWhereIn.1.data';
        $file_name_where_in2 = $this->tmp_path . '_testSelectWhereIn.2.data';

        foreach ($file_data_names as $file_name) {
            $this->create_fake_csv_file($file_name, 2);
        }

        $this->db->insertBatchFiles('summing_url_views', $file_data_names, [
            'event_time', 'url_hash', 'site_id', 'views', 'v_00', 'v_55'
        ]);

        $st = $this->db->select('SELECT sum(views) as sum_x, min(v_00) as min_x FROM summing_url_views');
        $this->assertEquals(2136, $st->fetchOne('sum_x'));


        $whereIn_1 = [
            [85, 'x85x2'],
            [69, 'x69x2'],
            [20, 'x20x2'],
            [11, 'xxxxx'],
            [12, 'zzzzz']
        ];

        $whereIn_2 = [
            [11, 'x11x2'],
            [12, 'x12x1'],
            [13, 'x13x2'],
            [14, 'xxxxx'],
            [15, 'zzzzz']
        ];

        $this->make_csv_SelectWhereIn($file_name_where_in1, $whereIn_1);
        $this->make_csv_SelectWhereIn($file_name_where_in2, $whereIn_2);

        $whereIn = new \ClickHouseDB\WhereInFile();

        $whereIn->attachFile($file_name_where_in1, 'whin1', [
            'site_id'  => 'Int32',
            'url_hash' => 'String'
        ], \ClickHouseDB\WhereInFile::FORMAT_CSV);

        $whereIn->attachFile($file_name_where_in2, 'whin2', [
            'site_id'  => 'Int32',
            'url_hash' => 'String'
        ], \ClickHouseDB\WhereInFile::FORMAT_CSV);

        $result = $this->db->select('
        SELECT 
          url_hash,
          site_id,
          sum(views) as views 
        FROM summing_url_views 
        WHERE 
        (site_id,url_hash) IN (SELECT site_id,url_hash FROM whin1)
        or
        (site_id,url_hash) IN (SELECT site_id,url_hash FROM whin2)
        GROUP BY url_hash,site_id
        ', [], $whereIn);

        $result = $result->rowsAsTree('site_id');


        $this->assertEquals(11, $result['11']['site_id']);
        $this->assertEquals(20, $result['20']['site_id']);
        $this->assertEquals(24, $result['13']['views']);
        $this->assertEquals('x20x2', $result['20']['url_hash']);
        $this->assertEquals('x85x2', $result['85']['url_hash']);
        $this->assertEquals('x69x2', $result['69']['url_hash']);

        // --- drop
        foreach ($file_data_names as $file_name) {
            unlink($file_name);
        }
    }

    /**
     *
     */
    public function testInsertCSV()
    {
        $file_data_names = [
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.1.data',
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.2.data',
            $this->tmp_path . '_testInsertCSV_clickHouseDB_test.3.data'
        ];


        // --- make
        foreach ($file_data_names as $file_name) {
            $this->create_fake_csv_file($file_name, 2);
        }

        $this->create_table_summing_url_views();
        $stat = $this->db->insertBatchFiles('summing_url_views', $file_data_names, [
            'event_time', 'url_hash', 'site_id', 'views', 'v_00', 'v_55'
        ]);


        $st = $this->db->select('SELECT sum(views) as sum_x,min(v_00) as min_x FROM summing_url_views');
        $this->assertEquals(6408, $st->fetchOne('sum_x'));

        $st = $this->db->select('SELECT * FROM summing_url_views ORDER BY url_hash');
        $this->assertEquals(6408, $st->count());

        $st = $this->db->select('SELECT * FROM summing_url_views LIMIT 4');
        $this->assertEquals(2136, $st->countAll());


        $stat = $this->db->insertBatchFiles('summing_url_views', $file_data_names, [
            'event_time', 'url_hash', 'site_id', 'views', 'v_00', 'v_55'
        ]);

        $st = $this->db->select('SELECT sum(views) as sum_x, min(v_00) as min_x FROM summing_url_views');
        $this->assertEquals(2 * 6408, $st->fetchOne('sum_x'));

        // --- drop
        foreach ($file_data_names as $file_name) {
            unlink($file_name);
        }
    }

    /**
     *
     */
    public function testPing()
    {
        $result = $this->db->select('SELECT 12 as {key} WHERE {key} = :value', ['key' => 'ping', 'value' => 12]);
        $this->assertEquals(12, $result->fetchOne('ping'));
    }

    /**
     *
     */
    public function testSelectAsync()
    {
        $state1 = $this->db->selectAsync('SELECT 1 as {key} WHERE {key} = :value', ['key' => 'ping', 'value' => 1]);
        $state2 = $this->db->selectAsync('SELECT 2 as ping');

        $this->db->executeAsync();

        $this->assertEquals(1, $state1->fetchOne('ping'));
        $this->assertEquals(2, $state2->fetchOne('ping'));
    }

    /**
     *
     */
    public function testInfoRaw()
    {
        $this->create_table_summing_url_views();
        $this->insert_data_table_summing_url_views();


        $state = $this->db->select('SELECT sum(views) as sum_x, min(v_00) as min_x FROM summing_url_views');

        $this->assertFalse($state->isError());

        $this->assertArrayHasKey('starttransfer_time',$state->info());
        $this->assertArrayHasKey('size_download',$state->info());
        $this->assertArrayHasKey('speed_download',$state->info());
        $this->assertArrayHasKey('size_upload',$state->info());
        $this->assertArrayHasKey('upload_content',$state->info());
        $this->assertArrayHasKey('speed_upload',$state->info());
        $this->assertArrayHasKey('time_request',$state->info());

        $rawData=($state->rawData());

        $this->assertArrayHasKey('rows',$rawData);
        $this->assertArrayHasKey('meta',$rawData);
        $this->assertArrayHasKey('data',$rawData);
        $this->assertArrayHasKey('extremes',$rawData);


        $responseInfo=($state->responseInfo());
        $this->assertArrayHasKey('url',$responseInfo);
        $this->assertArrayHasKey('content_type',$responseInfo);
        $this->assertArrayHasKey('http_code',$responseInfo);
        $this->assertArrayHasKey('request_size',$responseInfo);
        $this->assertArrayHasKey('filetime',$responseInfo);
        $this->assertArrayHasKey('total_time',$responseInfo);
        $this->assertArrayHasKey('upload_content_length',$responseInfo);
        $this->assertArrayHasKey('primary_ip',$responseInfo);
        $this->assertArrayHasKey('local_ip',$responseInfo);


        $this->assertEquals(200, $responseInfo['http_code']);

    }

    /**
     *
     */
    public function testTableExists()
    {
        $this->create_table_summing_url_views();

        $this->assertEquals('summing_url_views', $this->db->showTables()['summing_url_views']['name']);

        $this->db->write("DROP TABLE IF EXISTS summing_url_views");
    }

    /**
     * @expectedException \ClickHouseDB\DatabaseException
     */
    public function testExceptionWrite()
    {
        $this->db->write("DRAP TABLEX")->isError();
    }

    /**
     * @expectedException \ClickHouseDB\DatabaseException
     * @expectedExceptionCode 60
     */
    public function testExceptionInsert()
    {
        $this->db->insert('bla_bla', [
            ['HASH1', [11, 22, 33]],
            ['HASH1', [11, 22, 55]],
        ], ['s_key', 's_arr']);
    }

    /**
     * @expectedException \ClickHouseDB\DatabaseException
     * @expectedExceptionCode 60
     */
    public function testExceptionSelect()
    {
        $this->db->select("SELECT * FROM XXXXX_SSS")->rows();
    }

    /**
     * @expectedException \ClickHouseDB\QueryException
     * @expectedExceptionCode 6
     */
    public function testExceptionConnects()
    {
        $config = [
            'host'     => 'x',
            'port'     => '8123',
            'username' => 'x',
            'password' => 'x',
            'settings' => ['max_execution_time' => 100]
        ];

        $db = new ClickHouseDB\Client($config);
        $db->ping();
    }

    /**
     *
     */
    public function testSettings()
    {
        $config = [
            'host'     => 'x',
            'port'     => '8123',
            'username' => 'x',
            'password' => 'x',
            'settings' => ['max_execution_time' => 100]
        ];

        $db = new ClickHouseDB\Client($config);
        $this->assertEquals(100, $db->settings()->getSetting('max_execution_time'));


        // settings via constructor
        $config = [
            'host' => 'x',
            'port' => '8123',
            'username' => 'x',
            'password' => 'x'
        ];
        $db = new ClickHouseDB\Client($config, ['max_execution_time' => 100]);
        $this->assertEquals(100, $db->settings()->getSetting('max_execution_time'));


        //
        $config = [
            'host' => 'x',
            'port' => '8123',
            'username' => 'x',
            'password' => 'x'
        ];
        $db = new ClickHouseDB\Client($config);
        $db->settings()->set('max_execution_time', 100);
        $this->assertEquals(100, $db->settings()->getSetting('max_execution_time'));


        $config = [
            'host' => 'x',
            'port' => '8123',
            'username' => 'x',
            'password' => 'x'
        ];
        $db = new ClickHouseDB\Client($config);
        $db->settings()->apply([
            'max_execution_time' => 100,
            'max_block_size' => 12345
        ]);

        $this->assertEquals(100, $db->settings()->getSetting('max_execution_time'));
        $this->assertEquals(12345, $db->settings()->getSetting('max_block_size'));
    }

    /**
     *
     */
    public function testSqlConditions()
    {
        $input_params = [
            'select_date' => ['2000-10-10', '2000-10-11', '2000-10-12'],
            'limit'       => 5,
            'from_table'  => 'table_x_y'
        ];

        $this->assertEquals(
            'SELECT * FROM table_x_y FORMAT JSON',
            $this->db->selectAsync('SELECT * FROM {from_table}', $input_params)->sql()
        );

        $this->assertEquals(
            'SELECT * FROM table_x_y WHERE event_date IN (\'2000-10-10\',\'2000-10-11\',\'2000-10-12\') FORMAT JSON',
            $this->db->selectAsync('SELECT * FROM {from_table} WHERE event_date IN (:select_date)', $input_params)->sql()
        );

        $this->assertEquals(
            'SELECT * FROM ZZZ LIMIT 5 FORMAT JSON',
            $this->db->selectAsync('SELECT * FROM ZZZ {if limit}LIMIT {limit}{/if}', $input_params)->sql()
        );

        $this->assertEquals(
            'SELECT * FROM ZZZ NOOPE FORMAT JSON',
            $this->db->selectAsync('SELECT * FROM ZZZ {if nope}LIMIT {limit}{else}NOOPE{/if}', $input_params)->sql()
        );
    }

    /**
     *
     */
    public function testInsertArrayTable()
    {
        $this->db->write("DROP TABLE IF EXISTS arrays_test_ints");
        $this->db->write('
            CREATE TABLE IF NOT EXISTS arrays_test_ints
            (
                s_key String,
                s_arr Array(UInt8)
            ) 
            ENGINE = Memory
        ');


        $state = $this->db->insert('arrays_test_ints', [
            ['HASH1', [11, 33]],
            ['HASH2', [11, 55]],
        ], ['s_key', 's_arr']);

        $this->assertGreaterThan(0, $state->totalTimeRequest());

        $state = $this->db->select('SELECT s_key, s_arr FROM arrays_test_ints ARRAY JOIN s_arr');

        $this->assertEquals(4, $state->count());
        $this->assertArraySubset([['s_key' => 'HASH1', 's_arr' => 11]], $state->rows());
    }

    /**
     *
     */
    public function testInsertTable()
    {
        $this->create_table_summing_url_views();

        $state = $this->insert_data_table_summing_url_views();

        $this->assertFalse($state->isError());


        $st = $this->db->select('SELECT sum(views) as sum_x, min(v_00) as min_x FROM summing_url_views');

        $this->assertEquals(122, $st->fetchOne('sum_x'));
        $this->assertEquals(9, $st->fetchOne('min_x'));

        $st = $this->db->select('SELECT * FROM summing_url_views ORDER BY url_hash');


        $this->assertEquals(4, $st->count());
        $this->assertEquals(0, $st->countAll());
        $this->assertEquals(0, sizeof($st->totals()));

        $this->assertEquals('HASH1', $st->fetchOne()['url_hash']);
        $this->assertEquals(2345, $st->extremesMin()['site_id']);

        $st = $this->db->select('
            SELECT url_hash, sum(views) as vv, avg(views) as avgv 
            FROM summing_url_views 
            WHERE site_id < 3333 
            GROUP BY url_hash 
            WITH TOTALS
        ');


        $this->assertEquals(2, $st->count());
        $this->assertEquals(0, $st->countAll());

        $this->assertEquals(34, $st->totals()['vv']);
        $this->assertEquals(17, $st->totals()['avgv']);


        $this->assertEquals(22, $st->rowsAsTree('url_hash')['HASH1']['vv']);

        // drop
        $this->db->write("DROP TABLE IF EXISTS summing_url_views");
    }
}
