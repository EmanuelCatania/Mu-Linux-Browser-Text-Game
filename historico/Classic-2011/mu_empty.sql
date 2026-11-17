/*
SQLyog Community Edition- MySQL GUI v6.03
Host - 5.0.18-nt : Database - muonline
*********************************************************************
Server version : 5.0.18-nt
*/

/*!40101 SET NAMES utf8 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

/*Table structure for table `characters` */

DROP TABLE IF EXISTS `characters`;

CREATE TABLE `characters` (
  `id` int(11) NOT NULL auto_increment,
  `owner` int(11) default NULL,
  `name` varchar(20) default NULL,
  `class` int(11) default NULL,
  `level` int(11) default NULL,
  `strength` int(11) default NULL,
  `agility` int(11) default NULL,
  `stamina` int(11) default NULL,
  `energy` int(11) default NULL,
  `points` int(11) default NULL,
  `fruits` int(11) default NULL,
  `skills` mediumtext,
  `experience` int(25) default NULL,
  `hp_cur` int(11) default NULL,
  `mp_cur` int(11) default NULL,
  `inventory` mediumtext,
  `equipment` mediumtext,
  `monsters` int(11) default NULL,
  `guild` int(11) default NULL,
  `quest` int(11) default NULL,
  `quest_target` varchar(50) default NULL,
  `quest_score` int(11) default NULL,
  `quest_done` mediumtext,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `characters` */

/*Table structure for table `chat` */

DROP TABLE IF EXISTS `chat`;

CREATE TABLE `chat` (
  `id` int(11) NOT NULL auto_increment,
  `time` int(11) default NULL,
  `poster` varchar(20) default NULL,
  `text` varchar(255) default NULL,
  `dest` varchar(20) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `chat` */

/*Table structure for table `golden` */

DROP TABLE IF EXISTS `golden`;

CREATE TABLE `golden` (
  `monster` varchar(50) default NULL,
  `encounter` int(20) default NULL,
  `map` varchar(50) default NULL,
  `defeated` varchar(50) default NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `golden` */

insert  into `golden`(`monster`,`encounter`,`map`,`defeated`) values ('golden_budge_dragon',1379607620,'lorencia','Laika'),('golden_goblin',1379262960,'noria','Laika'),('golden_knight',1379174432,'dungeon','Laika'),('golden_titan',1379174499,'devias','Laika'),('golden_devil',10000000,'lost_tower','Laika'),('golden_rabbit',1379607665,'elbeland','Laika'),('golden_lizard_king',10000000,'atlans','Laika');

/*Table structure for table `guilds` */

DROP TABLE IF EXISTS `guilds`;

CREATE TABLE `guilds` (
  `id` int(11) NOT NULL auto_increment,
  `name` varchar(20) default NULL,
  `founder` varchar(20) default NULL,
  `time` int(11) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `guilds` */

/*Table structure for table `market` */

DROP TABLE IF EXISTS `market`;

CREATE TABLE `market` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `item_code` varchar(256) default NULL,
  `owner_id` int(11) default NULL,
  `pstore_id` int(11) default NULL,
  `start_time` int(11) default NULL,
  `item_type` varchar(3) default NULL,
  `item_excellent` int(3) default NULL,
  `req_class` int(3) default NULL,
  `item_name` varchar(256) default NULL,
  `price` int(11) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `market` */

/*Table structure for table `online` */

DROP TABLE IF EXISTS `online`;

CREATE TABLE `online` (
  `id` int(11) NOT NULL auto_increment,
  `char_name` varchar(20) default NULL,
  `ip` varchar(20) default NULL,
  `session_id` varchar(50) default NULL,
  `location` varchar(20) default NULL,
  `time` int(11) default NULL,
  `exp` int(20) default NULL,
  `guild` int(11) default NULL,
  `class` int(11) default NULL,
  `pvp` int(11) default NULL,
  `pvp_time` int(11) default NULL,
  PRIMARY KEY  (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `online` */

/*Table structure for table `users` */

DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(11) unsigned NOT NULL auto_increment,
  `ip` varchar(20) default NULL,
  `regtime` int(11) unsigned default NULL,
  `username` varchar(30) default NULL,
  `password` varchar(40) default NULL,
  `email` varchar(255) default NULL,
  `lastvisit` int(11) unsigned default NULL,
  `active_char` varchar(11) default 'av1',
  `zen` int(11) unsigned default NULL,
  `pstore_open` int(11) unsigned default '0',
  `pstore_open_time` int(11) default NULL,
  `pstore` mediumtext,
  `pstore_prices` mediumtext,
  `npc32_kind` varchar(30) default NULL,
  `npc32` mediumtext,
  `vault` mediumtext,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

/*Data for the table `users` */

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
