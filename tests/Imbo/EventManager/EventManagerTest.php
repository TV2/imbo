<?php
/**
 * Imbo
 *
 * Copyright (c) 2011 Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package Imbo
 * @subpackage Unittests
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */

namespace Imbo\EventManager;

/**
 * @package Imbo
 * @subpackage Unittests
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011, Christer Edvartsen
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/christeredvartsen/imbo
 */
class EventManagerTest extends \PHPUnit_Framework_TestCase {
    private $request;
    private $response;
    private $manager;

    public function setUp() {
        $this->request = $this->getMock('Imbo\Http\Request\RequestInterface');
        $this->response = $this->getMock('Imbo\Http\Response\ResponseInterface');

        $this->manager = new EventManager($this->request, $this->response);
    }

    public function tearDown() {
        $this->request = null;
        $this->response = null;
        $this->manager = null;
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testAttachNonCallableParameter() {
        $callback = 'some string';

        $this->manager->attach('event', $callback);
    }

    public function testAttachAndTriggerEvents() {
        $callback1 = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $callback1->expects($this->exactly(2))->method('__invoke');

        $callback2 = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $callback2->expects($this->once())->method('__invoke');

        $callback3 = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();
        $callback4 = $this->getMockBuilder('stdClass')->setMethods(array('__invoke'))->getMock();

        $this->manager->attach('event1', $callback1)
                      ->attach('event2', $callback2)
                      ->attach('event3', $callback3)
                      ->attach('event2', $callback4)
                      ->attach('event4', $callback1);

        $this->manager->trigger('otherevent')
                      ->trigger('event1')
                      ->trigger('event2')
                      ->trigger('event4');
    }

    public function testAttachListener() {
        $listener = $this->getMock('Imbo\EventManager\Listener\ListenerInterface');
        $listener->expects($this->once())->method('getEvents')->will($this->returnValue(array('event')));
        $listener->expects($this->once())->method('__invoke')->with($this->isInstanceOf('Imbo\EventManager\EventInterface'));

        $this->manager->attachListener($listener);
        $this->manager->trigger('event');
    }
}
