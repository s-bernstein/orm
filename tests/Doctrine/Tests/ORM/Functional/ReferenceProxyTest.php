<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\Persistence\Proxy;
use Doctrine\Tests\Models\Company\CompanyAuction;
use Doctrine\Tests\Models\ECommerce\ECommerceProduct;
use Doctrine\Tests\Models\ECommerce\ECommerceShipping;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;
use function file_exists;
use function get_class;
use function str_replace;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Tests the generation of a proxy object for lazy loading.
 */
class ReferenceProxyTest extends OrmFunctionalTestCase
{
    protected function setUp(): void
    {
        $this->useModelSet('ecommerce');
        $this->useModelSet('company');

        parent::setUp();
    }

    public function createProduct(): int
    {
        $product = new ECommerceProduct();
        $product->setName('Doctrine Cookbook');
        $this->_em->persist($product);

        $this->_em->flush();
        $this->_em->clear();

        return $product->getId();
    }

    public function createAuction(): int
    {
        $event = new CompanyAuction();
        $event->setData('Doctrine Cookbook');
        $this->_em->persist($event);

        $this->_em->flush();
        $this->_em->clear();

        return $event->getId();
    }

    public function testLazyLoadsFieldValuesFromDatabase(): void
    {
        $id = $this->createProduct();

        $productProxy = $this->_em->getReference(ECommerceProduct::class, ['id' => $id]);
        self::assertEquals('Doctrine Cookbook', $productProxy->getName());
    }

    /** @group DDC-727 */
    public function testAccessMetatadaForProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        $class  = $this->_em->getClassMetadata(get_class($entity));

        self::assertEquals(ECommerceProduct::class, $class->name);
    }

    /** @group DDC-1033 */
    public function testReferenceFind(): void
    {
        $id = $this->createProduct();

        $entity  = $this->_em->getReference(ECommerceProduct::class, $id);
        $entity2 = $this->_em->find(ECommerceProduct::class, $id);

        self::assertSame($entity, $entity2);
        self::assertEquals('Doctrine Cookbook', $entity2->getName());
    }

    /** @group DDC-1033 */
    public function testCloneProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);

        $clone = clone $entity;
        assert($clone instanceof ECommerceProduct);

        self::assertEquals($id, $entity->getId());
        self::assertEquals('Doctrine Cookbook', $entity->getName());

        self::assertFalse($this->_em->contains($clone), 'Cloning a reference proxy should return an unmanaged/detached entity.');
        self::assertEquals($id, $clone->getId(), 'Cloning a reference proxy should return same id.');
        self::assertEquals('Doctrine Cookbook', $clone->getName(), 'Cloning a reference proxy should return same product name.');

        // domain logic, Product::__clone sets isCloned public property
        self::assertTrue($clone->isCloned);
        self::assertFalse($entity->isCloned);
    }

    /** @group DDC-733 */
    public function testInitializeProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);

        self::assertFalse($entity->__isInitialized__, 'Pre-Condition: Object is unitialized proxy.');
        $this->_em->getUnitOfWork()->initializeObject($entity);
        self::assertTrue($entity->__isInitialized__, 'Should be initialized after called UnitOfWork::initializeObject()');
    }

    /** @group DDC-1163 */
    public function testInitializeChangeAndFlushProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);
        $entity->setName('Doctrine 2 Cookbook');

        $this->_em->flush();
        $this->_em->clear();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        self::assertEquals('Doctrine 2 Cookbook', $entity->getName());
    }

    /** @group DDC-1022 */
    public function testWakeupCalledOnProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);

        self::assertFalse($entity->wakeUp);

        $entity->setName('Doctrine 2 Cookbook');

        self::assertTrue($entity->wakeUp, 'Loading the proxy should call __wakeup().');
    }

    public function testDoNotInitializeProxyOnGettingTheIdentifier(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);

        self::assertFalse($entity->__isInitialized__, 'Pre-Condition: Object is unitialized proxy.');
        self::assertEquals($id, $entity->getId());
        self::assertFalse($entity->__isInitialized__, "Getting the identifier doesn't initialize the proxy.");
    }

    /** @group DDC-1625 */
    public function testDoNotInitializeProxyOnGettingTheIdentifierDDC1625(): void
    {
        $id = $this->createAuction();

        $entity = $this->_em->getReference(CompanyAuction::class, $id);
        assert($entity instanceof CompanyAuction);

        self::assertFalse($entity->__isInitialized__, 'Pre-Condition: Object is unitialized proxy.');
        self::assertEquals($id, $entity->getId());
        self::assertFalse($entity->__isInitialized__, "Getting the identifier doesn't initialize the proxy when extending.");
    }

    public function testDoNotInitializeProxyOnGettingTheIdentifierAndReturnTheRightType(): void
    {
        $product = new ECommerceProduct();
        $product->setName('Doctrine Cookbook');

        $shipping = new ECommerceShipping();
        $shipping->setDays(1);
        $product->setShipping($shipping);
        $this->_em->persist($product);
        $this->_em->flush();
        $this->_em->clear();

        $id = $shipping->getId();

        $product = $this->_em->getRepository(ECommerceProduct::class)->find($product->getId());

        $entity = $product->getShipping();
        self::assertFalse($entity->__isInitialized__, 'Pre-Condition: Object is unitialized proxy.');
        self::assertEquals($id, $entity->getId());
        self::assertSame($id, $entity->getId(), "Check that the id's are the same value, and type.");
        self::assertFalse($entity->__isInitialized__, "Getting the identifier doesn't initialize the proxy.");
    }

    public function testInitializeProxyOnGettingSomethingOtherThanTheIdentifier(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);

        self::assertFalse($entity->__isInitialized__, 'Pre-Condition: Object is unitialized proxy.');
        self::assertEquals('Doctrine Cookbook', $entity->getName());
        self::assertTrue($entity->__isInitialized__, 'Getting something other than the identifier initializes the proxy.');
    }

    /** @group DDC-1604 */
    public function testCommonPersistenceProxy(): void
    {
        $id = $this->createProduct();

        $entity = $this->_em->getReference(ECommerceProduct::class, $id);
        assert($entity instanceof ECommerceProduct);
        $className = ClassUtils::getClass($entity);

        self::assertInstanceOf(Proxy::class, $entity);
        self::assertFalse($entity->__isInitialized());
        self::assertEquals(ECommerceProduct::class, $className);

        $restName      = str_replace($this->_em->getConfiguration()->getProxyNamespace(), '', get_class($entity));
        $restName      = substr(get_class($entity), strlen($this->_em->getConfiguration()->getProxyNamespace()) + 1);
        $proxyFileName = $this->_em->getConfiguration()->getProxyDir() . DIRECTORY_SEPARATOR . str_replace('\\', '', $restName) . '.php';
        self::assertTrue(file_exists($proxyFileName), 'Proxy file name cannot be found generically.');

        $entity->__load();
        self::assertTrue($entity->__isInitialized());
    }
}
