<?php
namespace BackQ\Message;

class Closure extends AbstractMessage
{
    /**
     * @var \Opis\Closure\SerializableClosure
     */
    protected $function;

    public function __construct(\Opis\Closure\SerializableClosure $function)
    {
        $this->function = $function;
    }

    /**
     * Executes the closure for this message
     *
     * @return mixed
     */
    public function execute()
    {
        $closure = $this->function->getClosure();
        return $closure();
    }
}
