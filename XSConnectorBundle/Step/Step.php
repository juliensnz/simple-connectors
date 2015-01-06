<?php

namespace Acme\Bundle\XSConnectorBundle\Step;

use Acme\Bundle\XSConnectorBundle\Processor\ProcessorInterface;
use Akeneo\Bundle\BatchBundle\Entity\StepExecution;
use Akeneo\Bundle\BatchBundle\Item\AbstractConfigurableStepElement;
use Akeneo\Bundle\BatchBundle\Item\InvalidItemException;
use Akeneo\Bundle\BatchBundle\Item\ItemProcessorInterface;
use Akeneo\Bundle\BatchBundle\Step\AbstractStep;
use Akeneo\Bundle\BatchBundle\Step\StepExecutionAwareInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Basic step implementation that simply run an itemStep
 *
 * @author    Julien Sanchez <julien@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class Step extends AbstractStep
{
    /**
     * @Assert\Valid
     * @var ItemProcessorInterface
     */
    protected $processor = null;

    /**
     * @var StepExecution
     */
    protected $stepExecution = null;

    /**
     * Set processor
     * @param ItemProcessorInterface $processor
     */
    public function setProcessor(ProcessorInterface $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Get processor
     * @return ItemProcessorInterface|null
     */
    public function getProcessor()
    {
        return $this->processor;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration()
    {
        $configuration = array();

        if ($this->processor instanceof AbstractConfigurableStepElement) {
            foreach ($this->processor->getConfiguration() as $key => $value) {
                if (!isset($configuration[$key]) || $value) {
                    $configuration[$key] = $value;
                }
            }
        }

        return $configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function setConfiguration(array $config)
    {
        if ($this->processor instanceof AbstractConfigurableStepElement) {
            $this->processor->setConfiguration($config);
        }
    }

    /**
     * Get the configurable step elements
     *
     * @return array
     */
    public function getConfigurableStepElements()
    {
        return ['processor' => $this->getProcessor()];
    }

    /**
     * {@inheritdoc}
     */
    public function doExecute(StepExecution $stepExecution)
    {
        $this->initializeStepElements($stepExecution);

        try {
            $this->processor->process();
        } catch (InvalidItemException $e) {
            $this->handleStepExecutionWarning($this->stepExecution, $this->processor, $e);
        }

        $this->getJobRepository()->updateStepExecution($stepExecution);
        $this->flushStepElements();
    }

    /**
     * @param StepExecution $stepExecution
     */
    protected function initializeStepElements(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
        foreach ($this->getConfigurableStepElements() as $element) {
            if ($element instanceof StepExecutionAwareInterface) {
                $element->setStepExecution($stepExecution);
            }
            $element->initialize();
        }
    }

    /**
     * Flushes step elements
     */
    public function flushStepElements()
    {
        foreach ($this->getConfigurableStepElements() as $element) {
            $element->flush();
        }
    }

    /**
     * Handle step execution warning
     *
     * @param StepExecution                   $stepExecution
     * @param AbstractConfigurableStepElement $element
     * @param InvalidItemException            $e
     */
    protected function handleStepExecutionWarning(
        StepExecution $stepExecution,
        AbstractConfigurableStepElement $element,
        InvalidItemException $e
    ) {
        if ($element instanceof AbstractConfigurableStepElement) {
            $warningName = $element->getName();
        } else {
            $warningName = get_class($element);
        }

        $stepExecution->addWarning($warningName, $e->getMessage(), $e->getMessageParameters(), $e->getItem());
        $this->dispatchInvalidItemEvent(
            get_class($element),
            $e->getMessage(),
            $e->getMessageParameters(),
            $e->getItem()
        );
    }
}
