<?php
/**
 * StreamReaderTest.php
 *
 * @author: chazer
 * @created: 21.12.15 17:02
 */

namespace JsonStreamReader;

class StreamReaderTest extends \PHPUnit_Framework_TestCase
{
    /** @var StreamReader */
    protected $reader;

    protected function createStream($string)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $string);
        rewind($stream);
        return $stream;
    }

    protected function setUp()
    {
        $this->reader = new StreamReader();
    }

    protected function tearDown()
    {
        $this->reader = null;
    }

    public function test()
    {
        $result = [];
        $this->reader->addListener('*', function ($keys, $value) use (&$result) {
            $result[implode('.', $keys)] = $value;
        });

        $data = [
            'int' => 100,
            'str' => 'value',
            'null' => null,
            'float' => 0.123456,
            'array' => [1, 2, 3],
        ];
        $stream = $this->createStream(json_encode($data));
        $this->reader->parse($stream);
        $this->assertEquals($data, $result);
    }

    public function testSkipDocFlag()
    {
        $result = [];
        $this->reader->addListener('*.*', function ($keys, $value) use (&$result) {
            $result[] = $value;
            return StreamReader::SKIP_DOC;
        });
        $data = ['items' => ['a', 'b', 'c'], 'values' => [10, 20, 30]];
        $stream = $this->createStream(json_encode($data));
        $this->reader->parse($stream);
        $this->assertEquals(['a'], $result);
    }

    public function testSkipKeyFlag()
    {
        $result = [];
        $this->reader->addListener('*.*', function ($keys, $value) use (&$result) {
            $result[] = $value;
            return StreamReader::SKIP_KEY;
        });
        $data = ['items' => ['a', 'b', 'c'], 'values' => [10, 20, 30]];
        $stream = $this->createStream(json_encode($data));
        $this->reader->parse($stream);
        $this->assertEquals(['a', 10], $result);
    }

    public function testNoStoreFlag()
    {
        $result = [];
        $this->reader->addListener('years.*.months.*', function ($keys, $value) {
            return StreamReader::NO_STORE;
        });
        $this->reader->addListener('years.*.months', function ($keys, $value) use (&$result) {
            $result[] = $value;
        });
        $data = [
            'years' => [
                '2014' => [
                    'months' => [
                        'jan' => 'a', 'feb' => 'b', 'mar' => 'c', 'apr' => 'd', 'may' => 'e', 'jun' => 'f',
                        'jul' => 'g', 'aug' => 'h', 'sep' => 'i', 'oct' => 'j', 'nov' => 'k', 'dec' => 'l',
                    ]],
                '2015' => [
                    'months' => [
                        'jan' => 'a', 'feb' => 'b', 'mar' => 'c', 'apr' => 'd', 'may' => 'e', 'jun' => 'f',
                        'jul' => 'g', 'aug' => 'h', 'sep' => 'i', 'oct' => 'j', 'nov' => 'k', 'dec' => 'l',
                    ]],
            ]];
        $stream = $this->createStream(json_encode($data));
        $this->reader->parse($stream);
        $this->assertEquals([[], []], $result);
    }
}
