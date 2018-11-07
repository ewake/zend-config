<?php
/**
 * @see       https://github.com/zendframework/zend-config for the canonical source repository
 * @copyright Copyright (c) 2005-2017 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-config/blob/master/LICENSE.md New BSD License
 */

namespace ZendTest\Config;

use Interop\Container\ContainerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Zend\Config\Factory;
use Zend\Config\ReaderPluginManager;
use Zend\Config\StandaloneReaderPluginManager;
use Zend\Config\StandaloneWriterPluginManager;
use Zend\Config\WriterPluginManager;

/**
 * @group      Zend_Config
 */
class FactoryTest extends TestCase
{
    protected $tmpFiles = [];
    protected $originalIncludePath;

    protected function getTestAssetFileName($ext)
    {
        if (empty($this->tmpfiles[$ext])) {
            $this->tmpfiles[$ext] = tempnam(sys_get_temp_dir(), 'zend-config-writer').'.'.$ext;
        }
        return $this->tmpfiles[$ext];
    }

    public function setUp()
    {
        $this->originalIncludePath = get_include_path();
        set_include_path(__DIR__ . '/TestAssets');
        $this->resetPluginManagers();
    }

    public function tearDown()
    {
        set_include_path($this->originalIncludePath);

        foreach ($this->tmpFiles as $file) {
            if (file_exists($file)) {
                if (! is_writable($file)) {
                    chmod($file, 0777);
                }
                @unlink($file);
            }
        }

        $this->resetPluginManagers();
    }

    public function resetPluginManagers()
    {
        foreach (['readers', 'writers'] as $pluginManager) {
            $r = new ReflectionProperty(Factory::class, $pluginManager);
            $r->setAccessible(true);
            $r->setValue(null);
        }
    }

    public function testFromIni()
    {
        $config = Factory::fromFile(__DIR__ . '/TestAssets/Ini/include-base.ini');

        $this->assertEquals('bar', $config['base']['foo']);
    }

    public function testFromXml()
    {
        $config = Factory::fromFile(__DIR__ . '/TestAssets/Xml/include-base.xml');

        $this->assertEquals('bar', $config['base']['foo']);
    }

    public function testFromIniFiles()
    {
        $files = [
            __DIR__ . '/TestAssets/Ini/include-base.ini',
            __DIR__ . '/TestAssets/Ini/include-base2.ini'
        ];
        $config = Factory::fromFiles($files);

        $this->assertEquals('bar', $config['base']['foo']);
        $this->assertEquals('baz', $config['test']['bar']);
    }

    public function testFromXmlFiles()
    {
        $files = [
            __DIR__ . '/TestAssets/Xml/include-base.xml',
            __DIR__ . '/TestAssets/Xml/include-base2.xml'
        ];
        $config = Factory::fromFiles($files);

        $this->assertEquals('bar', $config['base']['foo']);
        $this->assertEquals('baz', $config['test']['bar']);
    }

    public function testFromPhpFiles()
    {
        $files = [
            __DIR__ . '/TestAssets/Php/include-base.php',
            __DIR__ . '/TestAssets/Php/include-base2.php'
        ];
        $config = Factory::fromFiles($files);

        $this->assertEquals('bar', $config['base']['foo']);
        $this->assertEquals('baz', $config['test']['bar']);
    }

    public function testFromIniAndXmlAndPhpFiles()
    {
        $files = [
            __DIR__ . '/TestAssets/Ini/include-base.ini',
            __DIR__ . '/TestAssets/Xml/include-base2.xml',
            __DIR__ . '/TestAssets/Php/include-base3.php',
        ];
        $config = Factory::fromFiles($files);

        $this->assertEquals('bar', $config['base']['foo']);
        $this->assertEquals('baz', $config['test']['bar']);
        $this->assertEquals('baz', $config['last']['bar']);
    }

    public function testFromIniAndXmlAndPhpFilesFromIncludePath()
    {
        $files = [
            'Ini/include-base.ini',
            'Xml/include-base2.xml',
            'Php/include-base3.php',
        ];
        $config = Factory::fromFiles($files, false, true);

        $this->assertEquals('bar', $config['base']['foo']);
        $this->assertEquals('baz', $config['test']['bar']);
        $this->assertEquals('baz', $config['last']['bar']);
    }

    public function testReturnsConfigObjectIfRequestedAndArrayOtherwise()
    {
        $files = [
            __DIR__ . '/TestAssets/Ini/include-base.ini',
        ];

        $configArray = Factory::fromFile($files[0]);
        $this->assertInternalType('array', $configArray);

        $configArray = Factory::fromFiles($files);
        $this->assertInternalType('array', $configArray);

        $configObject = Factory::fromFile($files[0], true);
        $this->assertInstanceOf('Zend\Config\Config', $configObject);

        $configObject = Factory::fromFiles($files, true);
        $this->assertInstanceOf('Zend\Config\Config', $configObject);
    }

    public function testNonExistentFileThrowsRuntimeException()
    {
        $this->expectException(RuntimeException::class);
        $config = Factory::fromFile('foo.bar');
    }

    public function testUnsupportedFileExtensionThrowsRuntimeException()
    {
        $this->expectException(RuntimeException::class);
        $config = Factory::fromFile(__DIR__ . '/TestAssets/bad.ext');
    }

    public function testFactoryCanRegisterCustomReaderInstance()
    {
        Factory::registerReader('dum', new Reader\TestAssets\DummyReader());

        $configObject = Factory::fromFile(__DIR__ . '/TestAssets/dummy.dum', true);
        $this->assertInstanceOf('Zend\Config\Config', $configObject);

        $this->assertEquals($configObject['one'], 1);
    }

    public function testFactoryCanRegisterCustomReaderPlugin()
    {
        $services      = $this->prophesize(ContainerInterface::class);
        $pluginManager = new ReaderPluginManager($services->reveal(), ['services' => [
            'DummyReader' => new Reader\TestAssets\DummyReader(),
        ]]);
        Factory::setReaderPluginManager($pluginManager);
        Factory::registerReader('dum', 'DummyReader');

        $configObject = Factory::fromFile(__DIR__ . '/TestAssets/dummy.dum', true);
        $this->assertInstanceOf('Zend\Config\Config', $configObject);

        $this->assertEquals($configObject['one'], 1);
    }

    public function testFactoryToFileInvalidFileExtension()
    {
        $this->expectException(RuntimeException::class);
        $result = Factory::toFile(__DIR__.'/TestAssets/bad.ext', []);
    }

    public function testFactoryToFileNoDirInHere()
    {
        $this->expectException(RuntimeException::class);
        $result = Factory::toFile(__DIR__.'/TestAssets/NoDirInHere/nonExisiting/dummy.php', []);
    }

    public function testFactoryWriteToFile()
    {
        $config = ['test' => 'foo', 'bar' => [0 => 'baz', 1 => 'foo']];

        $file = $this->getTestAssetFileName('php');
        $result = Factory::toFile($file, $config);

        // build string line by line as we are trailing-whitespace sensitive.
        $expected = "<?php\n";
        $expected .= "return array(\n";
        $expected .= "    'test' => 'foo',\n";
        $expected .= "    'bar' => array(\n";
        $expected .= "        0 => 'baz',\n";
        $expected .= "        1 => 'foo',\n";
        $expected .= "    ),\n";
        $expected .= ");\n";

        $this->assertEquals(true, $result);
        $this->assertEquals($expected, file_get_contents($file));
    }

    public function testFactoryToFileWrongConfig()
    {
        $this->expectException(InvalidArgumentException::class);
        $result = Factory::toFile('test.ini', 'Im wrong');
    }

    public function testFactoryRegisterInvalidWriter()
    {
        $this->expectException(InvalidArgumentException::class);
        Factory::registerWriter('dum', new Reader\TestAssets\DummyReader());
    }

    public function testFactoryCanRegisterCustomWriterInstance()
    {
        Factory::registerWriter('dum', new Writer\TestAssets\DummyWriter());

        $file = $this->getTestAssetFileName('dum');

        $res = Factory::toFile($file, ['one' => 1]);

        $this->assertEquals($res, true);
    }

    public function testFactoryCanRegisterCustomWriterPlugin()
    {
        $services = $this->prophesize(ContainerInterface::class);
        $pluginManager = new WriterPluginManager($services->reveal(), ['services' => [
            'DummyWriter' => new Writer\TestAssets\DummyWriter(),
        ]]);
        Factory::setWriterPluginManager($pluginManager);
        Factory::registerWriter('dum', 'DummyWriter');

        $file = $this->getTestAssetFileName('dum');

        $res = Factory::toFile($file, ['one' => 1]);
        $this->assertEquals($res, true);
    }

    public function testDefaultReaderPluginManagerIsStandaloneVariant()
    {
        $readers = Factory::getReaderPluginManager();
        $this->assertInstanceOf(StandaloneReaderPluginManager::class, $readers);
    }

    public function testDefaultWriterPluginManagerIsStandaloneVariant()
    {
        $writers = Factory::getWriterPluginManager();
        $this->assertInstanceOf(StandaloneWriterPluginManager::class, $writers);
    }
}
