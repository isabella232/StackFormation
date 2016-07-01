<?php

namespace StackFormation;

use Aws\CloudFormation\Exception\CloudFormationException;
use StackFormation\Exception\StackNotFoundException;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Helper\Table;

class Observer
{

    protected $stack;
    protected $stackFactory;
    protected $output;

    public function __construct(Stack $stack, StackFactory $stackFactory, \Symfony\Component\Console\Output\OutputInterface $output)
    {
        $this->stack = $stack;
        $this->stackFactory = $stackFactory;
        $this->output = $output;
    }

    public function deleteOnSignal()
    {
        $terminator = new Terminator($this->stack, $this->output);
        $terminator->setupSignalHandler();
        return $this;
    }

    public function observeStackActivity($pollInterval = 20)
    {
        $printedEvents = [];
        $first = true;
        $stackGone = false;
        $lastStatus = '';
        do {
            if ($first) {
                $first = false;
            } else {
                sleep($pollInterval);
            }

            try {
                // load fresh instance for updated status
                $this->stack = $this->stackFactory->getStack($this->stack->getName(), true);
                $lastStatus = $this->stack->getStatus();

                $this->output->writeln("-> Polling... (Stack Status: $lastStatus)");

                $logMessages = [];

                $rows = [];
                foreach ($this->stack->getEvents() as $eventId => $event) {
                    if (!in_array($eventId, $printedEvents)) {
                        $printedEvents[] = $eventId;
                        $rows[] = [
                            // $event['Timestamp'],
                            Helper::decorateStatus($event['Status']),
                            $event['ResourceType'],
                            $event['LogicalResourceId'],
                            wordwrap($event['ResourceStatusReason'], 40, "\n"),
                        ];

                        if (!count($logMessages)) {
                            $logMessages = Helper::getDetailedLogFromResourceStatusReason($event['ResourceStatusReason']);
                        }
                    }
                }

                $table = new Table($this->output);
                $table->setRows($rows);
                $table->render();

                $this->printLogMessages($logMessages);

            } catch (CloudFormationException $exception) {
                // TODO: use refineException instead
                $message = \StackFormation\Helper::extractMessage($exception);
                if ($message == "Stack [{$this->stack->getName()}] does not exist") {
                    $stackGone = true;
                    $this->output->writeln("-> Stack gone.");
                } else {
                    throw $exception;
                }

            } catch (StackNotFoundException $exception) {
                $stackGone = true;
                $this->output->writeln("-> Stack gone.");
            }
        } while (!$stackGone && strpos($lastStatus, 'IN_PROGRESS') !== false);

        $this->printStatus($lastStatus);
        $this->printOutputs();

        return in_array($lastStatus, ['CREATE_COMPLETE', 'UPDATE_COMPLETE', 'DELETE_IN_PROGRESS']) ? 0 : 1;
    }

    protected function printOutputs()
    {
        $this->output->writeln("== OUTPUTS ==");
        try {
            $rows = [];
            foreach ($this->stack->getOutputs() as $key => $value) {
                $value = strlen($value) > 100 ? substr($value, 0, 100) . "..." : $value;
                $rows[] = [$key, $value];
            }

            $table = new Table($this->output);
            $table
                ->setHeaders(['Key', 'Value'])
                ->setRows($rows);
            $table->render();
        } catch (\Exception $e) {
            // never mind...
        }
    }

    /**
     * @param $lastStatus
     */
    protected function printStatus($lastStatus)
    {
        $formatter = new FormatterHelper();
        $formattedBlock = (strpos($lastStatus, 'FAILED') !== false)
            ? $formatter->formatBlock(['Error!', 'Last Status: ' . $lastStatus], 'error', true)
            : $formatter->formatBlock(['Completed', 'Last Status: ' . $lastStatus], 'info', true);
        $this->output->writeln("\n\n$formattedBlock\n\n");
    }

    /**
     * @param $logMessages
     */
    protected function printLogMessages(array $logMessages)
    {
        if (count($logMessages)) {
            $this->output->writeln('');
            $this->output->writeln("====================");
            $this->output->writeln("Detailed log output:");
            $this->output->writeln("====================");
            foreach ($logMessages as $line) {
                $this->output->writeln(trim($line));
            }
        }
    }
}
