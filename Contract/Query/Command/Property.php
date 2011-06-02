<?php

/*
 * This file is part of the Orient package.
 *
 * (c) Alessandro Nadalin <alessandro.nadalin@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Property interface is responsible to set the property (belonging to a class)
 * to manipulate.
 *
 * @package    Orient
 * @subpackage Query
 * @author     Alessandro Nadalin <alessandro.nadalin@gmail.com>
 */

namespace Orient\Contract\Query\Command;

interface Property
{
    /**
     * Builds a new object setting the $property to manipulate.
     *
     * @param   string  $property
     */
    public function __construct($property);

    /**
     * Sets the class of the property to manipulate.
     *
     * @param   string  $class
     * @return  Property
     */
    public function on($class);
}