<?php
namespace Pixelant\PxaProductManager\UserFunction;

use Pixelant\PxaProductManager\Utility\MainUtility;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class SolrProductLink
{
    /**
     * @var ContentObjectRenderer
     */
    public $cObj = null;

    /**
     * Generate link for solr
     *
     * @param string $content
     * @param array $params
     * @return string
     */
    public function getLink(/** @noinspection PhpUnusedParameterInspection */ $content, array $params): string
    {
        $pagePid = (int)($params['pageUid'] ?? 0);
        $productUid = (int)($this->cObj->data['uid'] ?? 0);
        $languageUid = (int)($this->cObj->data['__solr_index_language'] ?? 0);

        if ($pagePid === 0 || $productUid === 0) {
            throw new \UnexpectedValueException(
                '"$productUid" and "$pagePid" - could not be 0.',
                1517571016147
            );
        }

        $linkArguments = $this->buildLinksArguments($productUid);
        $urlParams = [
            'parameter' => $pagePid,
            'useCacheHash' => 1,
            'additionalParams' => '&L=' . $languageUid . '&' . http_build_query($linkArguments),
        ];

        return $this->cObj->typolink_URL($urlParams);
    }

    /**
     * Wrapper for testing purpose
     *
     * @param int $productUid
     * @return array
     */
    protected function buildLinksArguments(int $productUid): array
    {
        return MainUtility::buildLinksArguments($productUid);
    }
}
