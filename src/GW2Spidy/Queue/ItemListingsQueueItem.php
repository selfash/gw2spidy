<?php

namespace GW2Spidy\Queue;

use \DateTime;
use \Exception;
use GW2Spidy\TradingPostSpider;
use GW2Spidy\DB\Item;
use GW2Spidy\DB\BuyListing;
use GW2Spidy\DB\SellListing;
use GW2Spidy\DB\ItemQuery;
use GW2Spidy\DB\BuyListingQuery;
use GW2Spidy\DB\SellListingQuery;

use GW2Spidy\Util\RedisQueue\RedisPriorityIdentifierQueueItem;

class ItemListingsQueueItem extends RedisPriorityIdentifierQueueItem {
    const ONE_DAY = 86400;
    const ONE_HOUR = 3600;
    const THREE_HOURS = 10900;
    const FIFTEEN_MIN = 900;
    const FIVE_MIN = 300;

    protected $item;

    public function __construct($input) {
        if ($input instanceof Item) {
            $this->item = $input;
        } else {
            $this->item = ItemQuery::create()->findPk($input);
        }
    }

    public function getIdentifier() {
        return $this->item->getDataId();
    }

    public function getPriority() {
        return time() + $this->getItemPriority();
    }

    public function getItemPriority() {
        if ($this->item->getItemTypeId() == null) {
            return 24 * 60 * 60;
        }

        switch ($this->item->getItemType()->getTitle()) {
            case 'Weapon':
            case 'Armor':
                if ($this->item->getRarity() >= 3) {
                    if ($this->item->getRestrictionLevel() > 60) {
                        return self::FIFTEEN_MIN;
                    } else if ($this->item->getRestrictionLevel() > 40) {
                        return self::ONE_HOUR;
                    } else {
                        return self::THREE_HOURS;
                    }
                } else if ($this->item->getRarity() >= 2) {
                    return self::THREE_HOURS;
                } else {
                    return self::ONE_DAY;
                }

                break;

            case 'Gathering':
            case 'Tool':
                return self::ONE_DAY;

                break;

            case 'Trophy':
                if ($this->item->getRarity() >= 2) {
                    return self::FIFTEEN_MIN;
                } else {
                    return self::ONE_DAY;
                }

                break;

            case 'Gizmo':
                if ($this->item->getRarity() >= 5) {
                    return self::ONE_HOUR;
                } else {
                    return self::THREE_HOURS;
                }

                break;

            case 'Mini':
            case 'Bag':
            case 'Crafting Material':
                return self::FIFTEEN_MIN;

                break;

            case 'Container':
                if ($this->item->getRarity() >= 2) {
                    return self::FIFTEEN_MIN;
                } else {
                    return self::ONE_HOUR;
                }

                break;

            case 'Consumable':
            case 'Upgrade Component':
            case 'Trinket':
                return self::ONE_HOUR;

                break;

            default:
                throw new Exception("Unknown type {$this->item->getItemType()->getTitle()}");

                break;
        }
    }

    protected function getListings() {
        $now  = new DateTime();
        $item = $this->item;
        $listings = TradingPostSpider::getInstance()->getAllListingsById($item->getDataId());
        $sell = $listings[TradingPostSpider::LISTING_TYPE_SELL];
        $buy  = $listings[TradingPostSpider::LISTING_TYPE_BUY];

        $lowestSell = null;
        $lowestBuy  = null;

        $q = 0;
        $l = 0;
        if (count($sell)) {
            $lowestSell = reset($sell);

            foreach ($sell as $s) {
                $q += $s['quantity'];
                $l += $s['listings'];
            }
        }

        $sellListing = new SellListing();
        $sellListing->setItem($item);
        $sellListing->setListingDate($now);
        $sellListing->setListingTime($now);
        $sellListing->setQuantity($q);
        $sellListing->setListings($l);

        if ($lowestSell) {
            $sellListing->setUnitPrice($lowestSell['unit_price']);
            $item->setMinSaleUnitPrice($lowestSell['unit_price']);
        }

        $sellListing->save();

        $q = 0;
        $l = 0;
        if (count($buy)) {
            $lowestBuy = reset($buy);

            foreach ($buy as $b) {
                $q += $b['quantity'];
                $l += $b['listings'];
            }
        }

        $buyListing = new BuyListing();
        $buyListing->setItem($item);
        $buyListing->setListingDate($now);
        $buyListing->setListingTime($now);
        $buyListing->setQuantity($q);
        $sellListing->setListings($l);

        if ($lowestBuy) {
            $buyListing->setUnitPrice($lowestBuy['unit_price']);
            $item->setMaxOfferUnitPrice($lowestBuy['unit_price']);
        }

        $buyListing->save();

        $item->save();
    }

    protected function updateTrending() {
        if ($this->getItemPriority() > self::ONE_HOUR) {
            $item->setSalePriceChangeLastHour(0);
            $item->setOfferPriceChangeLastHour(0);

            $item->save();

            return;
        }

        $onehourago = new DateTime();
        $onehourago->sub(new \DateInterval('PT1H'));
        $item = $this->item;

        $q = SellListingQuery::create()
                ->filterByItemId($item->getDataId())
                ->filterByListingDate('now')
                ->filterByListingTime($onehourago, \Criteria::GREATER_THAN)
                ->orderByListingTime(\Criteria::ASC);

        $oneHourAgoSellListing = $q->findOne();

        if (!$oneHourAgoSellListing || $oneHourAgoSellListing->getUnitPrice() <= 0 || $item->getMinSaleUnitPrice() <= 0) {
            $item->setSalePriceChangeLastHour(0);
        } else {
            $item->setSalePriceChangeLastHour((($item->getMinSaleUnitPrice() - $oneHourAgoSellListing->getUnitPrice()) / $oneHourAgoSellListing->getUnitPrice()) * 100);
        }

        $q = BuyListingQuery::create()
                ->filterByItemId($item->getDataId())
                ->filterByListingDate('now')
                ->filterByListingTime($onehourago, \Criteria::GREATER_THAN)
                ->orderByListingTime(\Criteria::ASC);

        $oneHourAgoBuyListing = $q->findOne();

        if (!$oneHourAgoBuyListing || $oneHourAgoBuyListing->getUnitPrice() <= 0 || $item->getMaxOfferUnitPrice() <= 0) {
            $item->setOfferPriceChangeLastHour(0);
        } else {
            $item->setOfferPriceChangeLastHour((($item->getMaxOfferUnitPrice() - $oneHourAgoBuyListing->getUnitPrice()) / $oneHourAgoBuyListing->getUnitPrice()) * 100);
        }

        $item->save();
    }

    public function work() {
        $this->getListings();
        $this->updateTrending();

        return $this->item;
    }

    public function requeue() {
        $newQueueItem = clone $this;
        $this->manager->enqueue($newQueueItem);
    }
}

?>