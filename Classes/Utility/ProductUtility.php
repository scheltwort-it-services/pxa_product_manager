<?php
declare(strict_types=1);

namespace Pixelant\PxaProductManager\Utility;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Pavlo Zaporozkyi <pavlo@pixelant.se>, Pixelant
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Pixelant\PxaProductManager\Domain\Model\Category;
use Pixelant\PxaProductManager\Domain\Model\Product;
use Pixelant\PxaProductManager\Domain\Repository\CategoryRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\QueryGenerator;
use TYPO3\CMS\Core\Database\RelationHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Class ProductUtility
 * @package Pixelant\PxaProductManager\Utility
 */
class ProductUtility
{
    /**
     * Store compare list with this key
     */
    const COMPARE_LIST_SESSION_NAME = 'tx_pxaproductmanager_compare_uids';

    /**
     * Name of cookie that keep latest visited product
     */
    const LATEST_VISITED_COOKIE_NAME = 'pxa_pm_latest_visited';

    /**
     * Name of cookie that keep wish list
     */
    const WISH_LIST_COOKIE_NAME = 'pxa_pm_wish_list';

    /**
     * Name of cookie that keep order state
     */
    const ORDER_STATE_COOKIE_NAME = 'pxa_pm_order_state';

    /**
     * Get array of uids of categories for product
     *
     * @param $product
     * @return array
     */
    public static function getProductCategoriesUids(int $product): array
    {
        $result = [];

        if ($product) {
            $configuration =
                $GLOBALS['TCA']['tx_pxaproductmanager_domain_model_product']['columns']['categories']['config'];

            /** @var RelationHandler $relationHandler */
            $relationHandler = GeneralUtility::makeInstance(RelationHandler::class);
            $relationHandler->start(
                '',
                'sys_category',
                'sys_category_record_mm',
                $product,
                'tx_pxaproductmanager_domain_model_product',
                $configuration
            );

            foreach ($relationHandler->itemArray as $item) {
                if ($item['id'] > 0) {
                    $result[] = $item['id'];
                }
            }
        }

        return $result;
    }

    /**
     * Get tree of parent categories of product, include product categories as top level
     *
     * @param int $productUid
     * @param bool $reverseOrder Default it goes from product categories up to top parents, could reverse order
     * @return array
     */
    public static function getProductCategoriesParentsTree(int $productUid, bool $reverseOrder = false): array
    {
        if ($productUid <= 0) {
            return [];
        }

        $categoryRepository = MainUtility::getObjectManager()->get(CategoryRepository::class);
        $categories = [];

        // Get product categories first
        /** @var Category $category */
        foreach ($categoryRepository->findByUidList(self::getProductCategoriesUids($productUid)) as $category) {
            $categories[$category->getUid()] = $category;
        }

        // Build parents tree for each category of product
        $trees = [];
        // Get parent categories
        /** @var Category $category */
        foreach ($categories as $category) {
            $parents = CategoryUtility::getParentCategories($category);
            if (!empty($parents)) {
                $trees[] = array_reverse($parents); // Later we need to take top level parent first
            }
        }

        // Find the largest tree
        $biggestTreeCount = 0;
        foreach ($trees as $tree) {
            $treeCount = count($tree);
            if ($treeCount > $biggestTreeCount) {
                $biggestTreeCount = $treeCount;
            }
        }

        // Go through each tree at take top level parent to build descending tree.
        $rootLineCategories = [];
        if ($biggestTreeCount > 0) {
            for ($i = 0; $i < $biggestTreeCount; $i++) {
                foreach ($trees as &$tree) {
                    if (count($tree) > 0) {
                        $category = array_shift($tree);
                        if (!array_key_exists($category->getUid(), $rootLineCategories)) {
                            $rootLineCategories[$category->getUid()] = $category;
                        }
                    }
                }
            }
        }

        // Add parents tree to product categories
        foreach (array_reverse($rootLineCategories) as $category) {
            if (!array_key_exists($category->getUid(), $categories)) {
                $categories[$category->getUid()] = $category;
            }
        }

        return $reverseOrder ? array_reverse($categories) : $categories;
    }

    /**
     * Get storage for plugin record
     *
     * @param int $pluginUid
     * @return string
     */
    public static function getStoragePidForPlugin(int $pluginUid): string
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tt_content');

        /** @noinspection PhpParamsInspection */
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $record = $queryBuilder
            ->select('pages', 'recursive')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($pluginUid, \PDO::PARAM_INT)
                )
            )
            ->execute()
            ->fetch();

        if (!empty($record['pages'])) {
            $recursive = (int)$record['recursive'];

            if ($recursive <= 0) {
                return $record['pages'];
            }

            /** @var QueryGenerator $queryGenerator */
            $queryGenerator = GeneralUtility::makeInstance(QueryGenerator::class);
            $recursiveStoragePids = $record['pages'];

            $storagePids = GeneralUtility::intExplode(',', $record['pages'], true);
            foreach ($storagePids as $startPid) {
                $pids = $queryGenerator->getTreeList($startPid, $recursive, 0, 1);
                if (!empty($pids)) {
                    $recursiveStoragePids .= ',' . $pids;
                }
            }

            return $recursiveStoragePids;
        }


        return '';
    }

    /**
     * Format product price
     *
     * @param float $price
     * @return string
     */
    public static function formatPrice(float $price): string
    {
        if ($format = LocalizationUtility::translate('priceFormat', 'PxaProductManager')) {
            $format = explode('|', trim($format, '|'));
        } else {
            $format = [2, '.', ' '];
        }

        return number_format(
            $price,
            (int)($format[0] ?? 2),
            (string)($format[1] ?? '.'),
            (string)($format[2] ?? ',')
        );
    }

    /**
     * Uids of wish list
     *
     * @return array
     */
    public static function getWishList(): array
    {
        $list = $_COOKIE[self::WISH_LIST_COOKIE_NAME] ?: '';

        return GeneralUtility::intExplode(',', $list);
    }

    /**
     * Check if product in wish list
     *
     * @param object|int $product
     * @return bool
     */
    public static function isProductInWishList($product): bool
    {
        $list = $_COOKIE[self::WISH_LIST_COOKIE_NAME] ?: '';

        return GeneralUtility::inList($list, is_object($product) ? $product->getUid() : (int)$product);
    }

    /**
     * Get calculated custom sorting
     *
     * @param Product $product
     * @return int
     */
    public static function getCalculatedCustomSorting(Product $product): int
    {
        $customSorting = 0;

        if ($product->getIsNew() || $product->getCategories()->count() > 0) {
            $pluginSettings = ConfigurationUtility::getSettings($product->getPid());
            if ($pluginSettings['customSorting']['enable']) {
                // Get "new" points
                if ($product->getIsNew()) {
                    $customSorting += (int)$pluginSettings['customSorting']['points']['new'];
                }
                // Get "category" points
                if ($product->getCategories()->count() > 0) {
                    foreach ($product->getCategories() as $category) {
                        $catUid = $category->getUid();
                        if (isset($pluginSettings['customSorting']['points']['categories'][$catUid])) {
                            $catPoint = $pluginSettings['customSorting']['points']['categories'][$catUid];
                            $customSorting += (int)$catPoint;
                        }
                    }
                }
            }
        }

        return $customSorting;
    }

    /**
     * Clean ongoing order info
     *
     * @return void
     */
    public static function cleanOngoingOrderInfo()
    {
        // Clean list
        MainUtility::cleanCookieValue(self::WISH_LIST_COOKIE_NAME);
        MainUtility::cleanCookieValue(self::ORDER_STATE_COOKIE_NAME);
    }

    /**
     * Get order state
     *
     * @return array
     */
    public static function getOrderState(): array
    {
        // If no cookie set - return empty set
        if (empty($_COOKIE[self::ORDER_STATE_COOKIE_NAME])) {
            return [];
        }

        // Otherwise get order state from cookie
        $orderState = json_decode(
            urldecode(
                base64_decode($_COOKIE[self::ORDER_STATE_COOKIE_NAME])
            ),
            true
        );

        if (!is_array($orderState)) {
            $orderState = [];
        }

        return $orderState;
    }
}
