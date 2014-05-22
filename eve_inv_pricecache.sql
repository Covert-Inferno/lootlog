CREATE TABLE IF NOT EXISTS `eve_inv_pricecache` (
  `type_id` int(11) NOT NULL,
  `cached_price` decimal(14,2) NOT NULL,
  `valid_till` datetime NOT NULL,
  PRIMARY KEY (`type_id`),
  KEY `type_id` (`type_id`),
  KEY `valid_till` (`valid_till`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
