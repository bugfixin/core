<?php

/*
 * Copyright (C) 2023 Deciso B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

 namespace OPNsense\DHCPv6\Api;

 use OPNsense\Base\ApiControllerBase;
 use OPNsense\Core\Backend;
 use OPNsense\Core\Config;
 use OPNsense\Firewall\Util;

class LeasesController extends ApiControllerBase
{
    public function searchLeaseAction()
    {
        $this->sessionClose();
        $inactive = $this->request->get('inactive');
        $selected_interfaces = $this->request->get('selected_interfaces');
        $backend = new Backend();
        $config = Config::getInstance()->object();
        $online = [];
        $leases = [];

        $ndp_data = json_decode($backend->configdRun('interface list ndp json'), true);

        foreach ($ndp_data as $ndp_entry) {
            array_push($online, $ndp_entry['mac'], $ndp_entry['ip']);
        }

        $raw_leases = json_decode($backend->configdpRun('dhcpd6 list leases', [$inactive]), true);
        foreach ($raw_leases as $raw_lease) {
            if (!array_key_exists('addresses', $raw_lease)) {
                continue;
            }

            /* set defaults */
            $lease = [];
            $lease['type'] = 'dynamic';
            $lease['status'] = 'offline';
            $lease['lease_type'] = $raw_lease['lease_type'];
            $lease['iaid'] = $raw_lease['iaid'];
            $lease['duid'] = $raw_lease['duid'];
            $lease['iaid_duid'] = $raw_lease['iaid_duid'];
            $lease['descr'] = '';
            $lease['if'] = '';

            if (array_key_exists('cltt', $raw_lease)) {
                $lease['cltt'] = date('Y/m/d H:i:s', $raw_lease['cltt']);
            }

            /* XXX we pick the first address, this will be fine for a typical deployment
            * according to RFC8415 section 6.6, but it should be noted that the protocol
            * (and isc-dhcpv6) is capable of handing over multiple addresses/prefixes to
            * a single client within a single lease. The backend accounts for this.
            */
            $seg = $raw_lease['addresses'][0];
            $lease['state'] = $seg['binding'] == 'free' ? 'expired' : $seg['binding'];
            if (array_key_exists('ends', $seg)) {
                $lease['ends'] =  date('Y/m/d H:i:s', $seg['ends']);
            }

            $lease['address'] = $seg['iaaddr'];
            $lease['online'] = in_array(strtolower($seg['iaaddr']), $online) ? 'online' : 'offline';

            $leases[] = $lease;
        }

        $sleases = json_decode($backend->configdRun('dhcpd6 list static 0'), true);
        $statics = [];
        foreach ($sleases['dhcpd'] as $slease) {
            $static = [
                'address' => $slease['ipaddrv6'],
                'type' => 'static',
                'cltt' => '',
                'ends' => '',
                'descr' => $slease['descr'],
                'iaid' => '',
                'duid' => $slease['duid'],
                'if_descr' => '',
                'if' => $slease['interface'],
                'state' => 'active',
                'status' => in_array(strtolower($slease['ipaddrv6']), $online) ? 'online' : 'offline'
            ];
            $statics[] = $static;
        }

        $leases = array_merge($leases, $statics);

        $mac_man = json_decode($backend->configdRun('interface list macdb json'), true);
        $interfaces = [];

        /* fetch interfaces ranges so we can match leases to interfaces */
        $if_ranges = [];
        foreach ($config->dhcpdv6->children() as $dhcpif => $dhcpifconf) {
            $if = $config->interfaces->$dhcpif;
            if (!empty((string)$if->ipaddrv6) && !empty((string)$if->subnetv6)) {
                $if_ranges[$dhcpif] = (string)$if->ipaddrv6.'/'.(string)$if->subnetv6;
            }
        }

        foreach ($leases as $idx => $lease) {
            $leases[$idx]['man'] = '';
            $leases[$idx]['mac'] = '';
            /* We infer the MAC from NDP data if available, otherwise we extract it out
             * of the DUID. However, RFC8415 section 11 states that an attempt to parse
             * a DUID to obtain a client's link-layer addresss is unreliable, as there is no
             * guarantee that the client is still using the same link-layer address as when
             * it generated its DUID. Therefore, if we can link it to a manufacturer, chances
             * are fairly high that this is a valid MAC address, otherwise we omit the MAC
             * address.
             */
            if (!empty(['address'])) {
                foreach ($ndp_data as $ndp) {
                    if ($ndp['ip'] == $lease['address']) {
                        $leases[$idx]['mac'] = $ndp['mac'];
                        $leases[$idx]['man'] = empty($ndp['manufacturer']) ? '' : $ndp['manufacturer'];
                        break;
                    }
                }
            }

            if (!empty($lease['duid'])) {
                $mac = '';
                $duid_type = substr($lease['duid'], 0, 5);
                if ($duid_type === "00:01" || $duid_type === "00:03") {
                    /* DUID generated based on LL addr with or without timestamp */
                    $hw_type = substr($lease['duid'], 6, 5);
                    if ($hw_type == "00:01") { /* HW type ethernet */
                        $mac = substr($lease['duid'], -17, 17);
                    }
                }

                if (!empty($mac)) {
                    $mac_hi = strtoupper(substr(str_replace(':', '', $mac), 0, 6));
                    if (array_key_exists($mac_hi, $mac_man)) {
                        $leases[$idx]['mac'] = $mac;
                        $leases[$idx]['man'] = $mac_man[$mac_hi];
                    }
                }
            }

            /* include interface */
            $intf = '';
            $intf_descr = '';
            if (!empty($lease['if'])) {
                $if = $config->interfaces->{$lease['if']};
                if (!empty((string)$if->ipaddrv6) && Util::isIpAddress((string)$if->ipaddrv6)) {
                    $intf = $lease['if'];
                    $intf_descr = (string)$if->descr;
                }
            } else {
                foreach ($if_ranges as $if_name => $if_range) {
                    if (Util::isIPInCIDR($lease['address'], $if_range)) {
                        $intf = $if_name;
                        $intf_descr = $config->interfaces->$if_name->descr;
                    }
                }
            }

            $leases[$idx]['if'] = $intf;
            $leases[$idx]['if_descr'] = $intf_descr;

            if (!empty($if_name) && !array_key_exists($if_name, $interfaces)) {
                $interfaces[$intf] = $intf_descr;
            }
        }

        $response = $this->searchRecordsetBase($leases, null, 'address', function ($key) use ($selected_interfaces) {
            if (empty($selected_interfaces) || in_array($key['if'], $selected_interfaces)) {
                return true;
            }

            return false;
        }, SORT_REGULAR);

        $response['interfaces'] = $interfaces;
        return $response;
    }

    public function searchPrefixAction()
    {
        $this->sessionClose();
        $backend = new Backend();
        $prefixes = [];

        $raw_leases = json_decode($backend->configdpRun('dhcpd6 list leases 1'), true);
        foreach ($raw_leases as $raw_lease) {
            if ($raw_lease['lease_type'] === 'ia-pd' && array_key_exists('prefixes', $raw_lease)) {
                $prefix = [];
                $prefix['lease_type'] = $raw_lease['lease_type'];
                $prefix['iaid'] = $raw_lease['iaid'];
                $prefix['duid'] = $raw_lease['duid'];
                if (array_key_exists('cltt', $raw_lease)) {
                    $prefix['cltt'] = date('Y/m/d H:i:s', $raw_lease['cltt']);
                }

                $prefix_raw = $raw_lease['prefixes'][0];
                $prefix['prefix'] = $prefix_raw['iaprefix'];
                if (array_key_exists('ends', $prefix_raw)) {
                    $prefix['ends'] =  date('Y/m/d H:i:s', $prefix_raw['ends']);
                }
                $prefix['state'] = $prefix_raw['binding'] == 'free' ? 'expired' : $prefix_raw['binding'];
                $prefixes[] = $prefix;
            }
        }

        return $this->searchRecordsetBase($prefixes, null, 'prefix', null, SORT_REGULAR);
    }

    public function delLeaseAction($ip)
    {
        $result = ["result" => "failed"];

        if ($this->request->isPost()) {
            $this->sessionClose();
            $response = json_decode((new Backend())->configdpRun("dhcpd6 remove lease", [$ip]), true);
            if ($response["removed_leases"] != "0") {
                $result["result"] = "deleted";
            }
        }

        return $result;
    }
}
