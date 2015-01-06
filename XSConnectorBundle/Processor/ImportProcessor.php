<?php

namespace Acme\Bundle\XSConnectorBundle\Processor;

use Akeneo\Bundle\BatchBundle\Item\ItemProcessorInterface;
use Symfony\Component\HttpFoundation\File\File;
use Akeneo\Bundle\BatchBundle\Entity\StepExecution;
use Akeneo\Bundle\BatchBundle\Item\AbstractConfigurableStepElement;
use Akeneo\Bundle\BatchBundle\Item\UploadedFileAwareInterface;
use Akeneo\Bundle\BatchBundle\Step\StepExecutionAwareInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Pim\Bundle\CatalogBundle\Doctrine\Query\ProductQueryFactoryInterface;
use Pim\Bundle\CatalogBundle\Updater\ProductUpdaterInterface;
use Pim\Bundle\CatalogBundle\Manager\ProductManager;

/**
 * Basic step implementation that simply run an import item step
 *
 * @author    Julien Sanchez <julien@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/MIT MIT
 */
class ImportProcessor extends AbstractConfigurableStepElement implements
    UploadedFileAwareInterface,
    StepExecutionAwareInterface,
    ProcessorInterface
{
    /**
     * @Assert\NotBlank(groups={"Execution"})
     */
    protected $filePath;

    /** @var StepExecution */
    protected $stepExecution;

    /** @var ProductQueryFactoryInterface */
    protected $productQueryFactory;

    /** @var ProductUpdaterInterface */
    protected $productUpdater;

    /** @var ProductManager */
    protected $productManager;

    /** @var mixed */
    protected $objectManager;

    public function __construct(
        ProductQueryFactoryInterface $productQueryFactory,
        ProductUpdaterInterface $productUpdater,
        ProductManager $productManager,
        $objectManager
    ) {
        $this->productQueryFactory = $productQueryFactory;
        $this->productUpdater      = $productUpdater;
        $this->productManager      = $productManager;
        $this->objectManager       = $objectManager;
    }

    public function process()
    {
        $file = file_get_contents($this->filePath);

        $lines  = explode(chr(10), $file);
        $header = explode(';', reset($lines));
        array_shift($lines);

        foreach ($lines as $line) {
            if ('' !== $line) {
                $item = array_combine($header, explode(';', $line));

                $product = $this->productQueryFactory
                    ->create(['default_locale' => 'en_US', 'default_scope' => 'ecommerce'])
                    ->addFilter('sku', '=', $item['sku'])
                    ->getQueryBuilder()
                    ->getQuery()
                    ->execute();

                $product = reset($product);

                if (null === $product) {
                    $product = $this->productManager->createProduct();
                }

                foreach ($item as $key => $value) {
                    if ('sku' != $key) {
                        $this->productUpdater->setValue([$product], $key, $value);
                    }
                }

                $this->stepExecution->incrementSummaryInfo('product_imported');
            }

            $this->objectManager->flush();
        }
    }

    /**
     * Get uploaded file constraints
     *
     * @return array
     */
    public function getUploadedFileConstraints()
    {
        return [new Assert\NotBlank()];
    }

    /**
     * Set uploaded file
     *
     * @param File $uploadedFile
     *
     * @return CsvReader
     */
    public function setUploadedFile(File $uploadedFile)
    {
        $this->filePath = $uploadedFile->getRealPath();

        return $this;
    }

    /**
     * Get file path
     * @return string $filePath
     */
    public function getFilePath()
    {
        return $this->filePath;
    }

    /**
     * Set file path
     * @param string $filePath
     *
     * @return CsvReader
     */
    public function setFilePath($filePath)
    {
        $this->filePath = $filePath;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setStepExecution(StepExecution $stepExecution)
    {
        $this->stepExecution = $stepExecution;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationFields()
    {
        return [
            'filePath' => array(
                'options' => array(
                    'label' => 'File path',
                    'help'  => 'Please provide a file path for the file to import'
                )
            )
        ];
    }
}
