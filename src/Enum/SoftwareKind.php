<?php

namespace Base\Forge\Enum;

/**
 * What a piece of software is delivered as: a package a project installs
 * (Composer, Unity's Package Manager), a game people play (in the page when
 * it has a WebGL build, else downloaded per platform), or an application
 * people install.
 */
enum SoftwareKind: string
{
    case PACKAGE = 'package';
    case GAME = 'game';
    case APP = 'app';
}
