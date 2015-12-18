<?php
/**
 * GuzzleStreamReader.php
 *
 * @author: chazer
 * @created: 21.12.15 16:18
 */

namespace JsonStreamReader;

use GuzzleHttp\Psr7\StreamWrapper;
use Psr\Http\Message\StreamInterface;

class GuzzleStreamReader extends StreamReader
{
    /**
     * @param StreamInterface $stream
     * @return StreamInterface
     */
    private static function wrapBodyStream(StreamInterface $stream)
    {
        $eofStream = false;
        $methods = [
            'close' => function () use ($stream, &$eofStream) {
                    $stream->close();
                    $eofStream = true;
                },
            'eof' => function () use ($stream, &$eofStream) {
                    return $eofStream || $stream->eof();
                }
        ];
        //\GuzzleHttp\Stream\FnStream::decorate($stream, $methods);
        return \GuzzleHttp\Psr7\FnStream::decorate($stream, $methods);
    }

    /**
     * @param StreamInterface $stream
     * @param array $options options:
     * <br> - 'close' — bool, close stream if no more active listeners
     * @throws \Exception
     */
    public function parse($stream, $options = [])
    {
        $close = isset($options['close']) ? $options['close'] : true;

        $wrapped = self::wrapBodyStream($stream, $close);

        if ($close) {
            $this->setCompleteCallback(function () use ($wrapped) {
                $wrapped->close();
            });
        }

        try {
            $resource = StreamWrapper::getResource($wrapped);
            $parser = new \JsonStreamingParser_Parser($resource, $this);
            $parser->parse();
        } catch (\Exception $e) {
            if ($close) {
                $wrapped->close();
            }
            throw $e;
        }
    }
}
