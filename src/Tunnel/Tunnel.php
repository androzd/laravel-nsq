<?php

namespace Merkeleon\Nsq\Tunnel;


use Exception;
use Merkeleon\Nsq\Exception\
{NsqException, ReadFromSocketException, SocketOpenException, SubscribeException, WriteToSocketException};
use Merkeleon\Nsq\Utility\Stream;
use Merkeleon\Nsq\Wire\Writer;
use OkStuff\PhpNsq\Tunnel\Config;

class Tunnel
{
    protected $subscribed;
    protected $config;
    protected $sock;
    protected $writer;
    protected $reader;
    protected $identify;

    public function __construct(Config $config, $identify)
    {
        $this->identify = $identify;
        $this->config   = $config;
        $this->writer   = [];
        $this->reader   = [];
    }

    /**
     * @param $queue
     * @return Tunnel
     * @throws SubscribeException
     */
    public function subscribe($queue, $channel = 'web')
    {
        if ($this->subscribed !== $queue)
        {
            try
            {
                $this->write(Writer::sub($queue, $channel));
                $this->subscribed = $queue;
            }
            catch (Exception $e)
            {
                throw new SubscribeException($e->getMessage(), $e->getCode());
            }
        }

        return $this;
    }

    /**
     * @return Tunnel
     * @throws Send
     */
    public function ready(): Tunnel
    {
        if ($this->subscribed === null)
        {
            throw new NsqException('Tunnel should be subscribed first');
        }

        $this->write(Writer::rdy(1));

        return $this;
    }

    /**
     * @return Config
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * @param int $len
     * @return string
     * @throws NsqException|ReadFromSocketException
     */
    public function read($len = 0)
    {
        try
        {
            $data         = '';
            $timeout      = $this->config->get("readTimeout")["default"];
            $this->reader = [$sock = $this->getSock()];

            while (strlen($data) < $len)
            {
                $readable = Stream::select($this->reader, $this->writer, 5);
                if ($readable > 0)
                {
                    $buffer = Stream::recvFrom($sock, $len);

                    $data .= $buffer;
                    $len  -= strlen($buffer);
                }
            }
        }
        catch (Exception $e)
        {
            $this->shoutdown();

            throw new ReadFromSocketException($e->getMessage(), $e->getCode());
        }

        return $data;
    }

    /**
     * Sends string to the socket
     *
     * @param string $buffer
     * @return Tunnel
     * @throws WriteToSocketException
     */
    public function write($buffer)
    {
        try
        {
            $savedBuffer  = $buffer;
            $timeout      = $this->config->get("writeTimeout")["default"];
            $this->writer = [$sock = $this->getSock()];

            while ($buffer != '')
            {
                $writable = Stream::select($this->reader, $this->writer, $timeout);
                if ($writable > 0)
                {
                    $buffer = substr($buffer, Stream::sendTo($sock, $buffer));
                }

            }
        }
        catch (Exception $e)
        {
            $this->shoutdown();

            throw new WriteToSocketException($e->getMessage(), $e->getCode());
        }

        return $this;
    }

    public function __destruct()
    {
        $this->shoutdown();
    }

    /**
     * Function destroys socket connection
     *
     * @return Tunnel
     */
    public function shoutdown()
    {
        if ($this->sock)
        {
            try
            {
                fclose($this->sock);

                $this->sock       = null;
                $this->subscribed = null;
            }
            catch (\Exception $e)
            {
                // This exception doesn't matter
            }
        }

        return $this;
    }

    /**
     * @return resource
     * @throws SocketOpenException|WriteToSocketException
     */
    public function getSock()
    {
        if (null === $this->sock)
        {
            try
            {
                $this->sock = Stream::pfopen($this->config->host, $this->config->port);
            }
            catch (Exception $e)
            {
                throw new SocketOpenException($e->getMessage(), $e->getCode());
            }

            if (false === $this->config->get("blocking"))
            {
                stream_set_blocking($this->sock, 0);
            }

            $this->write(Writer::MAGIC_V2);
            $this->write(Writer::identify($this->identify));
        }

        return $this->sock;
    }
}