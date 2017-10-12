<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var Collection
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    public function __construct(
        State $state,
        ProductRepositoryInterface $productRepository,
        Collection $collection,
        ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        UrlPersistInterface $urlPersist
    ) {
        $this->state = $state;
        $this->productRepository = $productRepository;
        $this->collection = $collection;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('iazel:regenurl')
            ->setDescription('Regenerate url for given products')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Products to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            )
        ;

        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        try {
            // this tosses an error if the areacode is not set.
            $this->state->getAreaCode();
        } catch (\Exception $e) {
            $this->state->setAreaCode('adminhtml');
        }

        $storeId = $inp->getOption('store');

        $this->collection->addStoreFilter($storeId)
            ->setStoreId($storeId)
            ->addAttributeToSelect(['url_path', 'url_key'])
            ->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED])
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
        ;

        $pids = $inp->getArgument('pids');
        if (!empty($pids)) {
            $this->collection->addIdFilter($pids);
        }

        $updateIds = [];
        foreach ($this->collection as $product) {
            if (!$product->getData('url_key') || !$product->getData('url_path')) {
                continue;
            }

            $updateIds[] = $product->getId();
        }

        // free up system resources
        unset($this->collection);

        foreach ($updateIds as $productId) {
            $product = $this->productRepository->getById(
                $productId,
                false,
                $storeId
            );

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $product->getId(),
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
            ]);

            try {
                $this->urlPersist->replace($this->productUrlRewriteGenerator->generate($product));
            } catch (\Exception $e) {
                $out->writeln('<error>Duplicated url for '. $product->getId() .'</error>');
            }

            unset($product);
        }

        $out->writeln('<info>Total updated products: ' . count($updateIds) . '</info>');
    }
}
