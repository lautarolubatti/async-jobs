<?php
namespace Workana\AsyncJobs\Tests;

use Exception;
use Mockery as m;
use Bernard\Envelope;
use Bernard\Queue;
use Bernard\Router;
use Bernard\Message;
use Workana\AsyncJobs\AsyncAction;
use Workana\AsyncJobs\Event\SuccessfulExecutionEvent;
use Workana\AsyncJobs\Event\WorkerShutdownEvent;
use Workana\AsyncJobs\Job;
use Workana\AsyncJobs\Retry\RetryStrategy;
use Workana\AsyncJobs\Worker;
use Workana\AsyncJobs\Normalizer\Accesor;
use Workana\AsyncJobs\Stopwatch;
use Workana\AsyncJobs\AsyncJobsEvents;
use Workana\AsyncJobs\Event\BeforeExecutionEvent;
use Workana\AsyncJobs\Event\AfterExecutionEvent;
use Workana\AsyncJobs\Event\RejectedExecutionEvent;
use Workana\AsyncJobs\Util\Sleeper;
use Workana\AsyncJobs\Process\ProcessManager;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class WorkerTest extends Test
{
    /**
     * @var Worker
     */
    protected $worker;

    /**
     * @var Mock<Queue>
     */
    protected $mockedQueue;

    /**
     * @var Mock<Router>
     */
    protected $mockedRouter;

    /**
     * @var Mock<EventDispatcherInterface>
     */
    protected $mockedEventDispatcher;

    /**
     * @var Mock<RetryStrategy>
     */
    protected $mockedRetryStrategy;

    public function setUp()
    {
        $this->mockedRouter = m::mock(Router::class);

        $this->mockedQueue = m::mock(Queue::class);
        $this->mockedQueue->shouldReceive('acknowledge')->with(m::type(Envelope::class));

        $this->mockedEventDispatcher = m::mock(EventDispatcherInterface::class);

        $this->mockedRetryStrategy = m::mock(RetryStrategy::class);

        $this->worker = new Worker(
            $this->mockedQueue,
            $this->mockedRouter,
            $this->mockedEventDispatcher,
            new ProcessManager(),
            $this->mockedRetryStrategy
        );
    }

    /**
     * Use mocked stopwatch
     *
     * @return Mock<Stopwatch>
     */
    private function useMockedStopwatch()
    {
        $stopwatch = m::mock(Stopwatch::class);
        Accesor::set($this->worker, 'stopwatch', $stopwatch);

        return $stopwatch;
    }

    /**
     * Use actual EventDispatcher
     *
     * @return EventDispatcher
     */
    private function useActualEventDispatcher()
    {
        $dispatcher = new EventDispatcher();
        Accesor::set($this->worker, 'eventDispatcher', $dispatcher);

        return $dispatcher;
    }

    public function testInvoke()
    {
        $stopwatch = $this->useMockedStopwatch();
        $stopwatch->shouldReceive('start')->once();
        $stopwatch->shouldReceive('elapsed')->andReturn(1);

        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::BEFORE_EXECUTION, m::type(BeforeExecutionEvent::class))
            ->once();

        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::SUCCESSFUL_EXECUTION, m::type(SuccessfulExecutionEvent::class))
            ->once();

        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::AFTER_EXECUTION, m::type(AfterExecutionEvent::class))
            ->once();

        $envelope = m::mock(Envelope::class);
        $envelope->shouldReceive('getMessage')->andReturn($message = new AsyncAction('Foo', 'Bar'));
        
        $this->mockedQueue->shouldReceive('acknowledge')->with($envelope)->once()->byDefault();

        $this->mockedRouter->shouldReceive('map')
            ->once()
            ->with($envelope)
            ->andReturn(function(Message $actualMessage) use ($message) {
                $this->assertSame($message, $actualMessage);
            });

        $this->worker->invoke($envelope);
    }

    public function testInvokeAndRejected()
    {
        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::BEFORE_EXECUTION, m::type(BeforeExecutionEvent::class))
            ->once();

        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::REJECTED_EXECUTION, m::type(RejectedExecutionEvent::class))
            ->once();

        $this->mockedEventDispatcher->shouldReceive('dispatch')
            ->with(AsyncJobsEvents::AFTER_EXECUTION, m::type(AfterExecutionEvent::class))
            ->once();

        $envelope = new Envelope(new AsyncAction('Foo', 'Bar'));

        $this->mockedQueue->shouldReceive('acknowledge')->with($envelope)->once()->byDefault();

        $this->mockedRetryStrategy->shouldReceive('handleRetry')
                ->once()
                ->with($envelope, m::type(Exception::class));

        $this->mockedRouter->shouldReceive('map')
            ->once()
            ->with($envelope)
            ->andReturn(function() {
                throw new Exception('testing exception');
            });

        $this->worker->invoke($envelope);
    }

    public function testRunMultipleMessagesAndQuit()
    {
        $this->mockedRouter->shouldReceive('map')
            ->times(3)
            ->andReturn(function() {});

        $eventDispatcher = $this->useActualEventDispatcher();

        $eventDispatcher->addListener(AsyncJobsEvents::AFTER_EXECUTION, function() {
            static $calls = 1;

            if ($calls == 3) {
                $this->worker->getProcessManager()->signals()->handleSignal(SIGTERM);
            }

            $calls++;
        });

        $envelope = m::mock(Envelope::class);
        $envelope->shouldReceive('getMessage')->andReturn($message = new AsyncAction('Foo', 'Bar'));

        $this->mockedQueue->shouldReceive('dequeue')->times(3)->andReturn($envelope);

        $this->worker->run();
    }

    public function testRunWithoutTasks()
    {
        $this->mockedRouter->shouldReceive('map')->never();

        $this->mockedQueue->shouldReceive('dequeue')->times(5)->andReturnUsing(function() {
            static $calls = 1;

            if ($calls == 5) {
                $this->worker->getProcessManager()->signals()->handleSignal(SIGTERM);
            }

            $calls++;

            return null;
        });

        $this->mockedEventDispatcher->shouldReceive('dispatch')
                ->once()
                ->with(AsyncJobsEvents::WORKER_SHUTDOWN, m::type(WorkerShutdownEvent::class));

        $sleeper = m::mock(Sleeper::class);
        $sleeper->shouldReceive('sleep')->with(1)->times(5);
        Accesor::set($this->worker, 'sleeper', $sleeper);

        $this->worker->run();
    }

    public function testSignalsWereBound()
    {
        $mockedProcessManager = m::mock(ProcessManager::class);
        
        $mockedProcessManager->shouldReceive('signals->registerHandler')
            ->with(SIGTERM, m::type('array'))
            ->once();
        
        $mockedProcessManager->shouldReceive('signals->registerHandler')
            ->with(SIGINT, m::type('array'))
            ->once();
        
        $mockedProcessManager->shouldReceive('signals->registerHandler')
            ->with(SIGQUIT, m::type('array'))
            ->once();

        $worker = new Worker(
            $this->mockedQueue,
            $this->mockedRouter,
            $this->mockedEventDispatcher,
            $mockedProcessManager,
            $this->mockedRetryStrategy
        );
    }
}