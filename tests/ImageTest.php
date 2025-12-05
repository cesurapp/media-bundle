<?php

namespace Cesurapp\MediaBundle\Tests;

use claviska\SimpleImage;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ImageTest extends WebTestCase
{
    public function testCrop(): void
    {
        // JPG
        $image = new SimpleImage()
            ->fromString(file_get_contents(__DIR__.'/resources/image.jpg'))
            ->crop(0, 0, 100, 100);
        $this->assertEquals(100, $image->getWidth());
        $this->assertEquals(100, $image->getHeight());

        // PNG
        $image = new SimpleImage()
            ->fromString(file_get_contents(__DIR__.'/resources/image.png'))
            ->crop(0, 0, 100, 100);
        $this->assertEquals(100, $image->getWidth());
        $this->assertEquals(100, $image->getHeight());
    }

    public function testCompress(): void
    {
        // JPG
        $image = new SimpleImage()->fromString(file_get_contents(__DIR__.'/resources/image.jpg'));
        $image->toFile(sys_get_temp_dir().'/image.jpg', null, 75);
        $this->assertLessThanOrEqual(327626, filesize(sys_get_temp_dir().'/image.jpg'));
        unlink(sys_get_temp_dir().'/image.jpg');

        // PNG to JPG
        $image = new SimpleImage()->fromString(file_get_contents(__DIR__.'/resources/image.png'));
        $image->toFile(sys_get_temp_dir().'/image.jpg', 'image/jpeg', 75);
        $this->assertLessThanOrEqual(62302, filesize(sys_get_temp_dir().'/image.jpg'));
        unlink(sys_get_temp_dir().'/image.jpg');
    }

    public function testResize(): void
    {
        // JPG
        $image = new SimpleImage();
        $image
            ->fromString(file_get_contents(__DIR__.'/resources/image.jpg'))
            ->bestFit(100, 100);
        $this->assertEquals(96, $image->getWidth());
        $this->assertEquals(100, $image->getHeight());
        $this->assertLessThanOrEqual(3829, strlen($image->toString(null, 75)));

        // PNG
        $image = new SimpleImage();
        $image
            ->fromString(file_get_contents(__DIR__.'/resources/image.png'))
            ->bestFit(100, 100);
        $this->assertEquals(100, $image->getWidth());
        $this->assertEquals(67, $image->getHeight());
        $this->assertLessThanOrEqual(10706, strlen($image->toString('image/png', 75)));
    }
}
