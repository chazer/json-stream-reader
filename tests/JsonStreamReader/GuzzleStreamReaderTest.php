<?php
/**
 * GuzzleStreamReaderTest.php
 *
 * @author: chazer
 * @created: 21.12.15 18:10
 */

namespace JsonStreamReader;

use GuzzleHttp\Psr7\Stream;

/**
 * Class GuzzleStreamReaderTest
 *
 * @property GuzzleStreamReader $reader
 *
 * @package JsonStreamReader
 */
class GuzzleStreamReaderTest extends StreamReaderTest
{
    protected function createStream($string)
    {
        $stream = new Stream(parent::createStream($string));
        return $stream;
    }

    protected function setUp()
    {
        $this->reader = new GuzzleStreamReader();
    }
}
