<?php

namespace Acme\Bundle\XSConnectorBundle\Processor;

use Akeneo\Bundle\BatchBundle\Entity\StepExecution;
use Akeneo\Bundle\BatchBundle\Item\AbstractConfigurableStepElement;
use Akeneo\Bundle\BatchBundle\Item\InvalidItemException;
use Akeneo\Bundle\BatchBundle\Item\ItemProcessorInterface;
use Akeneo\Bundle\BatchBundle\Item\UploadedFileAwareInterface;
use Akeneo\Bundle\BatchBundle\Step\StepExecutionAwareInterface;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use Pim\Bundle\CatalogBundle\Manager\ProductManager;
use Pim\Bundle\CatalogBundle\Query\ProductQueryBuilderFactoryInterface;
use Pim\Bundle\CatalogBundle\Updater\ProductUpdaterInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ValidatorInterface;

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

    /** @var ProductQueryBuilderFactoryInterface */
    protected $pdbFactory;

    /** @var ProductUpdaterInterface */
    protected $productUpdater;

    /** @var ProductManager */
    protected $productManager;

    /** @var SaverInterface */
    protected $productSaver;

    /** @var ValidatorInterface */
    protected $validator;

    public function __construct(
        ProductQueryBuilderFactoryInterface $pdbFactory,
        ProductUpdaterInterface $productUpdater,
        ProductManager $productManager,
        SaverInterface $productSaver,
        ValidatorInterface $validator
    ) {
        $this->pdbFactory     = $pdbFactory;
        $this->productUpdater = $productUpdater;
        $this->productManager = $productManager;
        $this->productSaver   = $productSaver;
        $this->validator      = $validator;
    }

    public function process()
    {
        $file = file_get_contents($this->filePath);

        $lines  = explode(chr(10), $file);
        $header = explode(';', reset($lines));
        array_shift($lines);

        $identifier = $this->productManager->getIdentifierAttribute();

        foreach ($lines as $line) {
            if ('' !== $line) {
                $item = array_combine($header, explode(';', $line));

                $product = $this->pdbFactory
                    ->create(['default_locale' => 'en_US', 'default_scope' => 'ecommerce'])
                    ->addFilter($identifier->getCode(), '=', $item[$identifier->getCode()])
                    ->getQueryBuilder()
                    ->getQuery()
                    ->execute();

                $product = reset($product);

                if (false === $product) {
                    $product = $this->productManager->createProduct();

                    $identifierValue = $this->productManager->createProductValue();
                    $identifierValue->setAttribute($this->productManager->getIdentifierAttribute());
                    $identifierValue->setData($item[$identifier->getCode()]);

                    $product->addValue($identifierValue);
                }

                foreach ($item as $key => $value) {
                    $columnInfo = $this->getColumnInfo($key);

                    if ($identifier->getCode() != $columnInfo['code'] && '' !== $value) {
                        $this->productUpdater->setValue(
                            [$product],
                            $columnInfo['code'],
                            $value,
                            $columnInfo['locale'],
                            $columnInfo['scope']
                        );
                    }
                }

                $this->productSaver->save($product);
                $this->stepExecution->incrementSummaryInfo('product_imported');
            }
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

    protected function getColumnInfo($column)
    {
        $infos = explode('-', $column);

        $columnInfos = [
            'locale' => null,
            'scope'  => null
        ];

        switch (count($infos)) {
            case 1:
                $columnInfos['code'] = $infos[0];
                break;
            case 2:
                $columnInfos['code']   = $infos[0];
                $columnInfos['locale'] = false === strpos($infos[1], '_') ? null : $infos[1];
                $columnInfos['scope']  = false === strpos($infos[1], '_') ? $infos[1] : null;

                break;
            case 3:
                $columnInfos['code']   = $infos[0];
                $columnInfos['locale'] = $infos[1];
                $columnInfos['scope']  = $infos[2];
                break;
        }

        return $columnInfos;
    }
}
