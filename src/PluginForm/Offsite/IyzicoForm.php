<?php

namespace Drupal\commerce_payment_iyzico\PluginForm\Offsite;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\commerce_payment_iyzico\PluginForm\Offsite\IyzipayBootstrap;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;

class IyzicoForm extends PaymentOffsiteForm  {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $configuration = $this->getConfiguration();

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;


    $parameters = \Drupal::routeMatch()->getParameters();
    $order = $parameters->get('commerce_order');
    $customer = $order->getCustomer();


    $billingProfile = $order->getBillingProfile();
    $billingAddress = $billingProfile->get('address')->getValue()[0];
    $billingData = array(
      'name' => $billingAddress['given_name'] . ($billingAddress['additional_name'] ? ' ' . $billingAddress['additional_name'] . ' ' : ''),
      'surname' => $billingAddress['family_name'],
      'country_code' => $billingAddress['country_code'],
      'city' => $billingAddress['administrative_area'],
      'address' => $billingAddress['address_line1'] . ($billingAddress['address_line2'] ? ' ' . $billingAddress['address_line2'] : '') . ($billingAddress['locality'] ? ' ' . $billingAddress['locality'] : ''),
      'zipcode' => $billingAddress['postal_code'],
      'phone' => $billingProfile->get('field_telefon')->getValue()[0]['value'],
      'identifier' => $billingProfile->get('field_identifier')->getValue()[0]['value']

    
    );

    $billingData['fullname'] = $billingData['name'] . ' ' . $billingData['surname'];

    IyzipayBootstrap::init();

    $iyzicoOptions = $this->getOptions();

    /*$options = new \Iyzipay\Options();
    $options->setApiKey("sandbox-C0o6XIZEnS6OTM72HEkHBhf89PUQ7LPn");
    $options->setSecretKey("sandbox-xyha0RYBS1IeQrFoc58Q8iF4oHqmDuOS");
    $options->setBaseUrl("https://sandbox-api.iyzipay.com");*/

    $CallbackUrl = \Drupal::request()->getSchemeAndHttpHost() . "/checkout/" . $order->id() . "/payment/return";

    $request = new \Iyzipay\Request\CreateCheckoutFormInitializeRequest();
    $request->setLocale(\Iyzipay\Model\Locale::TR);
    $request->setConversationId("123456789");
    $request->setPrice($order->getSubTotalPrice()->getNumber());
    $request->setPaidPrice($order->getTotalPrice()->getNumber());
    $request->setCurrency(\Iyzipay\Model\Currency::TL);
    $request->setBasketId($order->id());
    $request->setCallbackUrl($CallbackUrl);
    //$request->setEnabledInstallments($this->getConfiguration()['installments']);
    //$request->setPaymentChannel(\Iyzipay\Model\PaymentChannel::WEB);
    //$request->setPaymentGroup(\Iyzipay\Model\PaymentGroup::PRODUCT);

    $buyer = new \Iyzipay\Model\Buyer();
    $buyer->setId($order->getCustomerId());
    $buyer->setName($billingData['name']);
    $buyer->setSurname($billingData['surname']);
    $buyer->setGsmNumber($billingData['phone']);
    $buyer->setEmail($order->getEmail());
    $buyer->setIdentityNumber($billingData['identifier']);
    $buyer->setLastLoginDate(date("Y-m-d H:i:s", $customer->getLastLoginTime()));
    $buyer->setRegistrationDate(date("Y-m-d H:i:s", $customer->getCreatedTime()));
    $buyer->setRegistrationAddress($billingData['address']);
    $buyer->setIp($order->getIpAddress());
    $buyer->setCity($billingData['city']);
    $buyer->setCountry($billingData['country_code']);
    $buyer->setZipCode($billingData['zipcode']);

    $request->setBuyer($buyer);
    $shippingAddress = new \Iyzipay\Model\Address();
    $shippingAddress->setContactName($billingData['fullname']);
    $shippingAddress->setCity($billingData['city']);
    $shippingAddress->setCountry($billingData['country_code']);
    $shippingAddress->setAddress($billingData['address']);
    $shippingAddress->setZipCode($billingData['zipcode']);
    $request->setShippingAddress($shippingAddress);

    $billingAddress = new \Iyzipay\Model\Address();
    $billingAddress->setContactName($billingData['fullname']);
    $billingAddress->setCity($billingData['city']);
    $billingAddress->setCountry($billingData['country_code']);
    $billingAddress->setAddress($billingData['address']);
    $billingAddress->setZipCode($billingData['zipcode']);
    $request->setBillingAddress($billingAddress);

    $basketItems = array();
    foreach($order->getItems() as $order_item) {
      $newBasketItem = new \Iyzipay\Model\BasketItem();
      $newBasketItem->setId($order_item->id());
      $newBasketItem->setName($order_item->label());
      $newBasketItem->setCategory1($order_item->getPurchasedEntity()->getProduct()->getTitle());
      $item_type = in_array($order_item->getPurchasedEntity()->getProduct()->bundle(), $this->getConfiguration()['virtual_products']) ? \Iyzipay\Model\BasketItemType::VIRTUAL : \Iyzipay\Model\BasketItemType::PHYSICAL;
      $newBasketItem->setItemType($item_type);
      $newBasketItem->setPrice($order_item->getTotalPrice()->getNumber());
      $basketItems[] = $newBasketItem;
    }
    $request->setBasketItems($basketItems);

    $iyzicoResponse = \Iyzipay\Model\CheckoutFormInitialize::create($request, $iyzicoOptions);

    if ($iyzicoResponse->getStatus() == 'success') {

      $token = $iyzicoResponse->getToken();
      $iyzicoData = array();
      $iyzicoData['token'] = $token;

      $order->setData('commerce_payment_iyzico', $iyzicoData);
      $order->save();

      throw new NeedsRedirectException($iyzicoResponse->getPaymentPageUrl());
    }
    else {
      \Drupal::messenger()->addMessage($iyzicoResponse->getErrorMessage(), "error");
      throw new PaymentGatewayException($iyzicoResponse->getErrorMessage());
    }
  }

    /**
   * @return array
   */
  private function getConfiguration() {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_quickpay_gateway\Plugin\Commerce\PaymentGateway\RedirectCheckout $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    return $payment_gateway_plugin->getConfiguration();
  }

  private function getOptions() {
    IyzipayBootstrap::init();

    $iyzicoOptions = new \Iyzipay\Options();
    $iyzicoOptions->setApiKey($this->getConfiguration()['api_key']);
    $iyzicoOptions->setSecretKey($this->getConfiguration()['security_key']);
    $iyzicoOptions->setBaseUrl($this->getConfiguration()['mode'] == 'live' ? $this->getConfiguration()['live_url'] : $this->getConfiguration()['sandbox_url']);

    return $iyzicoOptions;
  }

}
