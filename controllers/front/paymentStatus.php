<?php
/**
 * Class przelewy24paymentStatusModuleFrontController
 *
 * @author Przelewy24
 * @copyright Przelewy24
 * @license https://www.gnu.org/licenses/gpl.html
 *
 */

/**
 * Class Przelewy24paymentStatusModuleFrontController
 */
class Przelewy24paymentStatusModuleFrontController extends ModuleFrontController
{
    /**
     * Process change of payment status.
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function postProcess()
    {
        try {
            $this->postProcessInternal();
        } catch (Przelewy24HttpException $ex) {
            header('HTTP/1.1 ' . $ex->getCode() . ' ' . $ex->getMessage());
            header('Content-Type: text/plain');
            echo $ex->getCode(), ' ', $ex->getMessage();
        }
        exit;
    }

    /**
     * Internal code to process change of payment status.
     *
     * @throws Przelewy24HttpException
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function postProcessInternal()
    {
        if ('REST' === Tools::getValue('status')) {
            $statusSupport = new Przelewy24RestStatusSupport();
        } elseif (Tools::getValue('p24_session_id')) {
            $statusSupport = new Przelewy24ClassicStatusSupport();
        } else {
            $statusSupport = null;
        }

        $logMessage = 'postProcess ' . $statusSupport->getPayloadForLog();

        if (Tools::strlen($logMessage) >= Przelewy24Logger::LOG_MESSAGE_LIMIT) {
            throw new Przelewy24HttpException('Payload Too Large', 413);
        }
        PrestaShopLogger::addLog($logMessage, 1);

        if ($statusSupport && $statusSupport instanceof Przelewy24StatusSupportInterface) {
            PrestaShopLogger::addLog('przelewy24paymentStatusModuleFrontController', 1);

            list($cartId) = explode('|', $statusSupport->getSessionId(), 2);
            $cartId = (int)$cartId;

            $cart = new Cart($cartId);
            $przelewy24ServicePaymentData = new Przelewy24PaymentData($cart);

            if (empty($cart) || !isset($cart->id) || $cartId < 1) {
                throw new Przelewy24HttpException('Not Found', 404);
            }

            Context::getContext()->currency = Currency::getCurrencyInstance((int)$cart->id_currency);
            Cache::store("p24_customer", $cart->id_customer );
            $orderId = $przelewy24ServicePaymentData->getFirstOrderId();

            $total = (float)$cart->getOrderTotal(true, Cart::BOTH);
            $idOrderState = (int)Configuration::get('P24_ORDER_STATE_1');
            $customer = new Customer($cart->id_customer);
            $currency = new Currency($cart->id_currency);

            $addExtracharge = 0;
            if (!$orderId) {
                $this->module->validateOrder(
                    (int)$cart->id,
                    $idOrderState,
                    $total,
                    $this->module->displayName,
                    null,
                    array(),
                    (int)$currency->id,
                    false,
                    $customer->secure_key
                );
                $addExtracharge = 1;
                $orderId = $przelewy24ServicePaymentData->getFirstOrderId();

                /* PrestaShop require us to clear the cart after action above. */
                unset($this->context->cookie->id_cart);
            }

            if (1 === $addExtracharge) {
                $servicePaymentOptions = new Przelewy24ServicePaymentOptions(new Przelewy24());
                $servicePaymentOptions->setExtrachargeByOrderId($orderId);
            }
            $order = new Order($orderId);

            $amount = Przelewy24Helper::p24AmountFormat($przelewy24ServicePaymentData->getTotalAmountWithExtraCharge());
            $currency = new Currency($order->id_currency);
            $suffix = Przelewy24Helper::getSuffix($currency->iso_code);

            if ($statusSupport->verify($amount, $currency, $suffix)) {
                $newStatus = (int)Configuration::get('P24_ORDER_STATE_2');
                $przelewy24ServicePaymentData->setStateOnOrderCollection($newStatus);
                Przelewy24Order::saveOrder(
                    $statusSupport->getP24OrderId(),
                    $orderId,
                    $statusSupport->getSessionId(),
                    $statusSupport->getP24Number()
                );
                if ($statusSupport->possibleCardToSave()) {
                    $this->trySaveCard($suffix, $order);
                }
            } else {
                $newStatus = (int)(int)Configuration::get('PS_OS_ERROR');
                $przelewy24ServicePaymentData->setStateOnOrderCollection($newStatus);
            }
        }
    }

    /**
     * Try save card.
     *
     * @param string $suffix
     * @param Order $order
     * @throws Exception
     */
    private function trySaveCard($suffix, Order $order)
    {
        if (Przelewy24OneClickHelper::isOneClickEnable($suffix)
            && in_array((int)Tools::getValue('p24_method'), Przelewy24OneClickHelper::getCardPaymentIds())
        ) {
            if (Przelewy24CustomerSetting::initialize($order->id_customer)->card_remember) {
                $p24Soap = Przelewy24SoapInterfaceFactory::getForSuffix($suffix);
                $p24OrderId = (int)Tools::getValue('p24_order_id');
                if (Configuration::get('P24_ONECLICK_ENABLE' . $suffix)) {
                    $res = $p24Soap->getCardReferenceOneClickWithCheckCard(
                        Configuration::get('P24_API_KEY' . $suffix),
                        $p24OrderId
                    );
                    if (!empty($res)) {
                        $expires = Tools::substr($res->cardExp, 2, 2) . Tools::substr($res->cardExp, 0, 2);
                        if (date('ym') <= $expires) {
                            Przelewy24Recurring::remember(
                                $order->id_customer,
                                $res->refId,
                                $expires,
                                $res->mask,
                                $res->cardType
                            );
                        } else {
                            PrestaShopLogger::addLog(
                                'Error: expiration date  ' . var_export(
                                    $expires,
                                    true
                                ),
                                1
                            );
                        }
                    }
                }
            } else {
                PrestaShopLogger::addLog('Nie pamiętaj karty dla userID: ' . $order->id_customer, 1);
            }
        }
    }
}
