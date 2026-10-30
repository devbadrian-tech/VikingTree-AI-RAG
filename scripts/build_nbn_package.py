#!/usr/bin/env python3
"""Build a portable tree from the five supplied NBN revisions. Requires Poppler pdftotext.
Usage: python3 scripts/build_nbn_package.py --pdf-dir /path/to/pdfs [--pdftotext /path/to/pdftotext]
No model calls. Run tests/transfer.php to validate the generated graph and import/export.
"""
import argparse, hashlib, json, re, shutil, subprocess
from pathlib import Path

FILES = {
 'fibre': ('nbn-ethernet-(fibre)-test-and-diagnostic-checklist-v19.pdf', 'FTTP', '19', 'S2607-842708826-1787'),
 'fttn': ('nbn-Ethernet-(FTTB-and-FTTN)-Test-and-Diagnostic-Checklist-v14.pdf', 'FTTB / FTTN', '14', 'S2607-842708826-1785'),
 'fttc': ('nbn-Ethernet-(FTTC)-Test-and-Diagnostic-Checklist-v8.pdf', 'FTTC', '8', 'S2607-842708826-1786'),
 'hfc': ('nbn-ethernet-(hfc)-test-and-diagnostic-checklist-v15.pdf', 'HFC', '15', 'S2607-842708826-1784'),
 'wireless': ('nbn-ethernet-(wireless)-test-and-diagnostic-checklist-v14.pdf', 'Fixed Wireless', '14', 'S2607-842708826-1789'),
}
# Explicit heading/page anchors avoid confusing references in body text with headings.
# Entries cover troubleshooting, diagnostic interpretation, thresholds and evidence only.
CATALOG = {
'fibre': [
 ('led',9,'2.4.1.1',15,'3 Testing workflows','NTD versions, LED masking and indicator meanings'),
 ('initial',15,'3.1.1',19,'3.1.2','Initial checks, orders, outages and power cycle'),
 ('fault-type',19,'3.1.2',20,'3.1.3','Determine fault type from symptoms and NTD lights'),
 ('loss',20,'3.1.3.1',24,'3.1.3.2','Service loss, red optical or no data'),
 ('dropouts',24,'3.1.3.2',24,'3.1.3.3','Dropouts and optical-level checks'),
 ('power',24,'3.1.3.3',25,'3.1.3.4','No power and V1 battery faults'),
 ('speed',25,'3.1.3.4',29,'3.1.3.5','Slow speed and throughput evidence'),
 ('damage',29,'3.1.3.5',29,'3.1.3.6','Physical damage and equipment relocation'),
 ('no-voice',29,'3.1.3.6',30,'3.1.4','No voice on V1 UNI-V'),
 ('portal',30,'3.1.4.2',31,'3.1.4.3','Known faults, planned and emergency outages'),
 ('light-actions',31,'3.1.4.3',32,'3.1.5','Actions for NTD indicator faults'),
 ('data-outage',32,'3.1.5.1',33,'3.1.5.2','Single customer UNI-D outage'),
 ('ntd-fail',33,'3.1.5.2',33,'3.1.5.3','NTD Operational Status failure'),
 ('uni-fail',33,'3.1.5.3',33,'3.1.5.4','UNI-D Operational Status failure'),
 ('loop-fail',33,'3.1.5.4',33,'3.1.5.5','Loopback failure'),
 ('data-degradation',33,'3.1.5.5',34,'3.1.6','Single customer UNI-D degradation, TC-1/TC-2 performance tests'),
 ('mlt',34,'3.1.6.1',34,'3.1.6.2','V1 UNI-V Metallic Line Test and wiring isolation'),
 ('voice-outage',34,'3.1.6.2',35,'3.1.6.3','V1 UNI-V voice outage, SIP, ACS and DHCP'),
 ('voice-degradation',35,'3.1.6.3',36,'3.1.6.4','V1 UNI-V TR-069 voice degradation'),
 ('dtmf',36,'3.1.6.4',37,'3.1.6.5','V1 UNI-V OOB DTMF talk-off, wrong or missing digits'),
 ('all-outage',37,'3.1.6.5',37,'3.1.6.6','All services lost on one V1 NTD'),
 ('all-degradation',37,'3.1.6.6',38,'3.1.7','All services degraded on one V1 NTD'),
 ('poi-outage',38,'3.1.7.1',39,'3.1.7.2','Multiple customers data outage on one POI'),
 ('poi-degradation',39,'3.1.7.2',40,'3.1.7.3','Multiple customers data degradation on one POI'),
 ('poi-voice',40,'3.1.7.3',42,'3.1.7.4','V1 multiple customer UNI-V outage, degradation and OOB DTMF'),
 ('poi-all-outage',42,'3.1.7.4',43,'3.1.7.5','All services outage on one POI'),
 ('poi-all-degradation',43,'3.1.7.5',44,'3.1.8','All services degradation on one POI'),
 ('multi-poi-outage',44,'3.1.8.1',45,'3.1.8.2','Multiple POI service outage'),
 ('multi-poi-degradation',45,'3.1.8.2',47,'4 Raise','Multiple POI service degradation'),
 ('ticket',47,'4.1',47,'4.2','Service Restoration ticket types and appointments'),
 ('request',47,'4.2',48,'4.3','Network investigation Service Request and technical contact'),
 ('dispute',48,'4.3',52,'5 FTTP','Performance dispute eligibility, speed thresholds and evidence'),
 ('shs',52,'5 FTTP',55,'5.3.3','SHS categories, RAG and Suggested Action'),
 ('pulse',55,'5.3.3',58,'5.4','Pulse tests: network impairment, NTD, loopback, UNI-D, octet count, throughput, UNI-V, MLT, CPE detection'),
 ('hri',58,'5.4',59,'5.8','Refresh HRI, history and Advanced Diagnostics'),
 ('connectivity',59,'5.8',61,'5.8.2','Connectivity, operational state, CPE MAC, NTD port and identity'),
 ('performance',61,'5.8.2',62,'5.8.3','Optical signal and throughput interpretation'),
 ('stability',62,'5.8.3',64,'6 Additional','Unexpected, initiated and network dropout history'),
],
'fttn': [
 ('shs',7,'2 nbn',10,'3 Perform','SHS, Pulse, HRI history and Advanced Diagnostics'),
 ('portal',10,'3.1',10,'3.2','Known faults and outage checks'),
 ('led',10,'3.2',12,'3.3','VDSL2 modem LED interpretation'),
 ('basic',12,'3.3',13,'3.4','Isolation, power cycle, voiceband continuity, CPE, CVC and RADIUS'),
 ('outage',14,'3.4.1',16,'3.4.2','Single customer no sync, no data or authentication failure'),
 ('degradation',16,'3.4.2',21,'3.4.3','Single customer slow speed or dropouts'),
 ('poi-outage',21,'3.4.3',22,'3.4.4','Multiple customer VDSL2 outage'),
 ('poi-degradation',22,'3.4.4',23,'3.5','Multiple customer VDSL2 degradation'),
 ('next',23,'3.5',24,'4 Raise','Reportable fault and internal troubleshooting evidence'),
 ('ticket',24,'4.1',25,'5 Additional','Service Restoration Trouble Ticket'),
 ('speed-reference',29,'5.1.4',33,'5.1.6','Line rates, calculated layer 2 throughput and coexistence thresholds'),
 ('profiles',33,'5.1.6',35,'5.1.7','Standard, Stable and Repair profile restrictions'),
 ('modem',35,'5.1.7',36,'5.2','Registered and non-registered modem assurance'),
 ('test-access',36,'5.2',38,'5.2.4','Run tests, WRI history, execution status versus fault result'),
 ('problems',38,'5.2.4',42,'5.2.6','Graphs and historical detected problems with repair actions'),
 ('line-state',42,'5.2.6',45,'5.2.7','Line State test, stability and dropout eligibility'),
 ('lqd',45,'5.2.7',47,'5.2.8','LQD None, Normal and Robust modes, timing and outputs'),
 ('mlt',47,'5.2.8',47,'5.2.9','Metallic Line Test, FTTN-only eligibility and voltage/resistance thresholds'),
 ('selt',47,'5.2.9',49,'5.2.10','SELT isolation, Force mode, bridge taps and line breaks'),
 ('loopback',49,'5.2.10',50,'5.2.11','Loopback tests logical path, not copper or modem'),
 ('reset',50,'5.2.11',50,'5.3','DSLAM Port Reset, interruption and resynchronisation'),
 ('pi',50,'5.4',52,'6 Glossary','Enhanced Fault Rectification Performance Incident provision'),
],
'fttc': [
 ('portal',5,'2.1',5,'2.2','Known faults, planned and emergency outages'),
 ('led',5,'2.2',7,'2.3','NCD LAN, DSL, RPF and power indicators'),
 ('basic',7,'2.3',9,'2.4','Isolation, three-minute physical power cycle, CPE, CVC and RADIUS'),
 ('outage',9,'2.4.1',11,'2.4.2','Single customer no sync, no data or authentication failure'),
 ('degradation',11,'2.4.2',14,'2.4.3','Single customer slow speed or dropouts'),
 ('poi-outage',14,'2.4.3',15,'2.4.4','Multiple customer outage, DPU and NCD sample tests'),
 ('poi-degradation',15,'2.4.4',16,'2.5','Multiple customer degradation, CVC and sample line rates'),
 ('shs',16,'2.5',18,'3 Raise','Service Health Summary connectivity, performance and stability'),
 ('ticket',18,'3.1',19,'4 Additional','Service Restoration ticket and appointment'),
],
'hfc': [
 ('led',8,'2.3.2',11,'3 Testing','CM8200 and CM3500 indicator differences'),
 ('portal',11,'3.1.1',11,'3.1.2','Known faults, planned and emergency outages'),
 ('light-actions',11,'3.1.2',14,'3.2','NTD power, DS, US, Online and Ethernet light actions'),
 ('outage',14,'3.2.1.1',16,'3.2.1.2','Single customer service loss, relocation and NTD test'),
 ('ntd-fail',16,'3.2.1.2',17,'3.2.1.3','NTD Status failure, power cycle and startup sequence'),
 ('degradation',17,'3.2.1.3',19,'3.2.2','Single customer slow speed or dropouts'),
 ('poi-outage',19,'3.2.2.1',20,'3.2.2.2','Single POI data outage across customers'),
 ('poi-degradation',20,'3.2.2.2',21,'3.2.2.3','Single POI data degradation across customers'),
 ('poi-all-outage',21,'3.2.2.3',22,'3.2.2.4','Single POI all-services outage'),
 ('poi-all-degradation',22,'3.2.2.4',23,'3.2.3','Single POI all-services degradation'),
 ('multi-poi-outage',23,'3.2.3.1',24,'3.2.3.2','Multiple POI outage'),
 ('multi-poi-degradation',24,'3.2.3.2',26,'4 Raise','Multiple POI degradation'),
 ('ticket',26,'4.1',26,'4.2','Service Restoration fault types and appointments'),
 ('request',26,'4.2',27,'4.3','Network investigation Service Request and lead technical contact'),
 ('dispute',27,'4.3',33,'5 HFC','SHS disagreement, MAC mismatch, dropout patterns and dispute evidence'),
 ('shs',33,'5 HFC',36,'5.3.3','SHS categories, Suggested Action and appointment lead times'),
 ('pulse',36,'5.3.3',37,'5.4','Automatic Service Health Pulse, 45-minute validity'),
 ('hri',37,'5.4',39,'5.8','Refresh HRI, Advanced Diagnostics and history'),
 ('connectivity',39,'5.8',42,'5.8.2','Operational state, CPE, NTD MAC, speed/duplex and service alignment'),
 ('performance',42,'5.8.2',45,'5.8.3','HFC signal, network utilisation, upgrades and throughput'),
 ('stability',45,'5.8.3',47,'6 Additional','Dropout types, history and >=4 unexpected threshold'),
],
'wireless': [
 ('portal',7,'2.1',7,'2.2','Known faults and outage checks'),
 ('outage',8,'2.3.1',8,'2.3.2','Single customer outage and loopback'),
 ('degradation',8,'2.3.2',10,'2.4','Single customer speed, dropouts and time-of-day tests'),
 ('poi-outage',10,'2.4.1',11,'2.4.2','Same POI multiple customer outage'),
 ('poi-degradation',11,'2.4.2',11,'2.4.3','Same POI multiple customer degradation'),
 ('multi-poi-outage',11,'2.4.3',12,'2.4.4','Multiple POI outage, two AVCs per POI'),
 ('multi-poi-degradation',12,'2.4.4',13,'2.5','Multiple POI degradation, two AVCs per POI'),
 ('new-service',13,'2.5',14,'2.6','New service never worked, antenna, NTD ID and address'),
 ('weather',14,'2.6',15,'2.7','Weather, wet vegetation, mains outages and storms'),
 ('power',15,'2.7',16,'2.8','IDU power supply and replacement checks'),
 ('led',16,'2.8',19,'2.9','Power, Status, ODU and version-specific signal LEDs'),
 ('cpe',19,'2.9',21,'2.10','Cabling, CPE isolation, no MAC/no link and UNI-D LEDs'),
 ('thresholds',21,'2.10',23,'3 Service','Daily and busy-period thresholds, plan and WNTD eligibility'),
 ('shs',23,'3 Service',25,'3.3.2.1','SHS categories and Suggested Action'),
 ('optimisation',25,'3.3.2.1',26,'3.4','Home Fast/Superfast first-30-day optimisation actions'),
 ('hri',26,'3.4',27,'3.5','HRI history and test evidence'),
 ('connectivity',27,'3.5',29,'3.5.2','Service state, CPE, NTD identity and port'),
 ('performance',29,'3.5.2',33,'4 Raise','Signal, speed, cell/backhaul performance, age and upgrade information'),
 ('ticket',33,'4.1',33,'4.2','Service Restoration ticket and appointment'),
 ('request',33,'4.2',34,'5 IDU','Multiple-customer Service Request and technical contact'),
 ('led-reference',34,'5 IDU',37,'6 Glossary','IDU LED reference, V1/V2/V3 versus V4 signal colours'),
]}

# Issue documents deliberately distinguish scope; test catalog is a separate collection.
ISSUES = {
'fibre': [
 ('loss','Single customer service loss / red optical / no data','No internet, red optical or green optical with no data on FTTP.','loss'),
 ('dropouts','Single customer intermittent dropouts','FTTP repeatedly disconnects or optical level may be unstable.','dropouts'),
 ('speed','Single customer slow speed','FTTP throughput lower than ordered plan, constant or time-of-day performance issue.','speed'),
 ('power','NTD has no power or battery fault','FTTP NTD unlit after LED masking ruled out, red power or V1 battery/PSU issue.','power'),
 ('damage','Physical damage or relocated NTD','FTTP equipment damaged or moved from installed location.','damage'),
 ('no-voice','V1 UNI-V phone not working','One V1 FTTP UNI-V voice service has no voice; excludes router VoIP on V3/V4.','voice-outage'),
 ('voice-degradation','V1 UNI-V voice quality / TR-069','One V1 FTTP UNI-V service has noise, one-way audio or voice dropouts under TR-069.','voice-degradation'),
 ('dtmf','V1 UNI-V wrong, missing or false DTMF digits','One V1 FTTP UNI-V telephone keypad/IVR has talk-off, wrong digits or no digits.','dtmf'),
 ('all-outage','All services lost on one V1 NTD','A single premises loses multiple/all data and voice services on its V1 FTTP NTD.','all-outage'),
 ('all-degradation','All services degraded on one V1 NTD','A single premises has multiple/all V1 FTTP services degraded.','all-degradation'),
 ('poi-outage','Multiple customers data outage on one POI','Several FTTP customers on one POI lose UNI-D data service.','poi-outage'),
 ('poi-degradation','Multiple customers data degradation on one POI','Several FTTP customers on one POI have slow speed or dropouts.','poi-degradation'),
 ('poi-voice','Multiple V1 UNI-V customers on one POI','Multiple V1 FTTP voice services on one POI have outage, degradation or OOB DTMF problems.','poi-voice'),
 ('poi-all-outage','Multiple customers all services outage on one POI','Multiple FTTP customers on one POI lose all data and voice services.','poi-all-outage'),
 ('poi-all-degradation','Multiple customers all services degraded on one POI','Multiple FTTP customers on one POI have degradation of all services.','poi-all-degradation'),
 ('multi-poi-outage','Multiple POIs service outage','FTTP service loss affects multiple customers across more than one POI.','multi-poi-outage'),
 ('multi-poi-degradation','Multiple POIs service degradation','FTTP slow speed/dropouts affect multiple customers across more than one POI.','multi-poi-degradation'),
 ('dispute','Performance evidence contradicts green SHS','ONLY AFTER CPE isolation and actual all-green SHS or unavailable throughput results contradict verified FTTP performance evidence. Initial slow-speed complaints with unknown diagnostics belong to Slow speed.','dispute'),
],
'fttn': [
 ('outage','Single customer no sync / no data / no authentication','One FTTB or FTTN VDSL2 service has no DSL sync, no dataflow or cannot authenticate.','outage'),
 ('degradation','Single customer slow speed or dropouts','One FTTB or FTTN service has reduced speed, instability, latency or intermittent DSL sync.','degradation'),
 ('poi-outage','Multiple customers service outage','Several FTTB/FTTN services have no sync or no dataflow; establish local area/POI scope.','poi-outage'),
 ('poi-degradation','Multiple customers service degradation','Several FTTB/FTTN services have slow speed or dropouts; establish local area/POI scope.','poi-degradation'),
],
'fttc': [
 ('outage','Single customer no sync / no data / no authentication','One FTTC NCD service has no DSL sync, no dataflow or authentication failure.','outage'),
 ('degradation','Single customer slow speed or dropouts','One FTTC service has low speed, incorrect Ethernet speed/duplex or intermittent drops.','degradation'),
 ('poi-outage','Multiple customers service outage','Several FTTC services lose sync/data; sample affected DPU ports and NCDs.','poi-outage'),
 ('poi-degradation','Multiple customers service degradation','Several FTTC services have reduced speed/dropouts, suspected shared cable or interference.','poi-degradation'),
],
'hfc': [
 ('outage','Single customer no service / no data','One HFC customer has service loss, including new service or NTD relocation issues.','outage'),
 ('degradation','Single customer slow speed or dropouts','One HFC customer has slow speeds or intermittent connectivity.','degradation'),
 ('poi-outage','Multiple customers data outage on one POI','Several HFC customers at one POI lose UNI-D data.','poi-outage'),
 ('poi-degradation','Multiple customers data degradation on one POI','Several HFC customers at one POI have UNI-D slow speeds/dropouts.','poi-degradation'),
 ('poi-all-outage','Multiple customers all services outage on one POI','Several HFC customers at one POI lose all services.','poi-all-outage'),
 ('poi-all-degradation','Multiple customers all services degraded on one POI','Several HFC customers at one POI have all services degraded.','poi-all-degradation'),
 ('multi-poi-outage','Multiple POIs service outage','Several HFC customers across more than one POI have service loss.','multi-poi-outage'),
 ('multi-poi-degradation','Multiple POIs service degradation','Several HFC customers across more than one POI have slow speeds/dropouts.','multi-poi-degradation'),
 ('dispute','Green SHS conflicts with symptoms / NTD MAC mismatch','ONLY AFTER an actual SHS result disagrees with verified fault evidence, or an on-site NTD MAC differs from SHS. Requires that established mismatch; an initial slow-speed or dropout complaint with unknown SHS belongs to the ordinary degradation pathway.','dispute'),
],
'wireless': [
 ('outage','Single customer service outage','One Fixed Wireless customer has no service.','outage'),
 ('degradation','Single customer slow speed or dropouts','One Fixed Wireless customer has reduced throughput or intermittent disconnections.','degradation'),
 ('poi-outage','Multiple customers outage on one POI','Several Fixed Wireless services on the same POI have no service.','poi-outage'),
 ('poi-degradation','Multiple customers degradation on one POI','Several Fixed Wireless services on the same POI have low speed/dropouts.','poi-degradation'),
 ('multi-poi-outage','Multiple POIs service outage','Fixed Wireless outage affects customers across several POIs.','multi-poi-outage'),
 ('multi-poi-degradation','Multiple POIs service degradation','Fixed Wireless slow speeds/dropouts affect customers across several POIs.','multi-poi-degradation'),
 ('new-service','New service never worked','Recently activated Fixed Wireless service never worked; verify antenna, NTD and address.','new-service'),
 ('power','No IDU power / ODU or signal LED fault','Fixed Wireless IDU has no power, ODU error, abnormal status or no signal.','led'),
 ('optimisation','Home Fast / Superfast optimisation','New/upgraded Fixed Wireless Home Fast or Superfast is under assessment, optimisation, needs a site visit or downgrade.','optimisation'),
]}

STAMP = '2026-10-30T00:00:00Z'
nodes, byid, sources, sections = [], {}, [], {}
def add(nid, parent, slug, title, kind, summary, content='', source=None):
    assert nid not in byid, nid
    n = dict(id=nid, parent_id=parent, slug=slug, title=title, kind=kind,
             summary=summary[:1200], overview='', content=content, summary_origin='manual',
             source=source, sort_order=len(nodes), layout=None, references=[], updated_at=STAMP)
    nodes.append(n); byid[nid]=n
    return n

def path(nid):
    n=byid[nid]
    return (path(n['parent_id']) if n['parent_id'] else 'viking:/')+'/'+n['slug']
def link(nid, label=None):
    return '['+(label or byid[nid]['title']).replace('[','').replace(']','')+']('+path(nid)+')'
def refid(k,key): return f'nbn_ref_{k}_{key.replace("-","_")}'
def clean_page(p):
    lines=[]
    for l in p.splitlines():
        l=re.sub(r' {2,}', ' | ', l.strip())
        if not l or l.startswith(('nbn-COMMERCIAL','nbn-Confidential','© ','Uncontrolled when printed','S2607-','nbn® Ethernet')) or re.match(r'Page \d+ of',l): continue
        lines.append(l)
    return '\n'.join(lines)
def anchor(pages, page, heading):
    m=re.search(r'^'+re.escape(heading)+r'(?=\s|$)',pages[page-1],re.M)
    if not m: raise ValueError(f'Missing page {page} heading {heading}')
    return sum(len(p)+1 for p in pages[:page-1])+m.start()

def evidence(k, issue):
    common = [
      'Service AVC/PRI, access technology, provisioned port, ordered plan/traffic class, affected service count and POI scope.',
      'Symptoms, first observed time, timezone, whether it ever worked, frequency/duration and current customer impact.',
      'Known INC/CRQ and in-flight order checks, with outcome and time.',
      'Every diagnostic: name, parameters/mode, start/end time and timezone, test/WRI/HRI ID, measured values, interpreted result, and raw-result attachment/reference.',
      'Customer checks, cabling/CPE isolation, device make/model/firmware, observed LEDs, power/reset actions with wait times, and before/after service verification.',
      'Internal RSP configuration/authentication/backhaul/CVC checks applicable to this case; what was ruled out and what remains unknown.',
    ]
    if k=='fibre': common += ['NTD version V1/V3/V4, battery backup, NTD/UNI-D status, loopback, optical levels and available SHS/Pulse results. For V1 UNI-V cases include UNI-V status, SIP/DHCP/ACS and MLT where required.']
    if k=='fttn': common += ['FTTB versus FTTN, modem sync versus DSLAM state, registered modem status, SRA, Standard/Stable/Repair profile, coexistence, line/Layer 2 rates, Line State/LQD/SELT and eligible MLT results. Record deliberate resets separately from unexpected resyncs.']
    if k=='fttc': common += ['NCD DSL/LAN/RPF/power states, DPU Port Status and line bitrate, NCD UNI-D status/speed/duplex, DPU/NCD resets and exact wait/retest times.']
    if k=='hfc': common += ['NTD model, physical on-site MAC/serial/address versus SHS values, original socket/relocation, NTD status, DS/US/Online/startup states, link speed/duplex and service configuration alignment.']
    if k=='wireless': common += ['WNTD version, NTD ID/serial and address, IDU/ODU/signal LEDs, weather/power correlation, antenna presence, loopback, SHS signal/speed/cell/backhaul and optimisation status.']
    if any(w in issue for w in ['speed','degradation','dispute']):
        common += ['Measured downstream/upstream Mbps, latency, test server/destination, exact time/timezone, device and direct Ethernet setup; ping/traceroute; plan-specific eligibility and the applicable source thresholds.']
        if k in ['fibre','hfc']: common += ['For the relevant speed/escalation case: two Ookla result URLs or 11-digit IDs (or permitted proprietary results), date/time and device, performed 12 hours apart. Preserve the source distinction between suggested dispute evidence and mandatory escalation evidence.']
        if k=='wireless': common += ['Speed tests morning, afternoon and evening, at least one hour apart; daily and rolling 14-day busy-period evidence where applicable. Do not substitute fixed-line thresholds.']
    if any(w in issue for w in ['poi','all-']):
        if k in ['fibre','wireless']: common += [('Minimum two AVCs per POI across multiple POIs.' if 'multi-poi' in issue else 'Minimum three individual AVC tests for a potential network-fault Service Request.')]
        elif k=='hfc': common += ['Minimum three individual AVC/NTD operational status tests for the multiple-customer case (including multiple POIs).']
        else: common += ['Sample affected services and record area/POI/common infrastructure, line state/bitrate and LQD where applicable; do not invent a sample quota.']
        common += ['Common start time/symptoms, affected AVC list, common CVC/S-VLAN/NNI/LAG/BNG/BRAS, applicable SIP/ACS/DHCP, backhaul provider confirmation, internal escalation and lead technical contact.']
    if k=='hfc' and issue=='dispute': common += ['Choose the exact dispute scenario: >30 minutes interruption in a calendar day, repeated significant degradation, MAC mismatch, interference pattern, or data transmission issue. Attach current/historical HRI/WRI and RADIUS/session/usage logs, plus physical/SHS MAC and address/kit-origin checks for mismatch.']
    return '\n'.join('- '+s for s in common)


def build(args):
    exe=args.pdftotext or shutil.which('pdftotext')
    if not exe: raise SystemExit('Install Poppler or pass --pdftotext.')
    add('nbn_troubleshooting',None,'nbn-troubleshooting','NBN troubleshooting','collection',
        'Customer NBN service faults. Choose confirmed access technology, then symptom and single/multiple customer scope. Resolve simple faults and gather evidence for reviewed escalation.',
        'Purpose: supply troubleshooting instructions to the portal diagnostic agent. Confirm technology from service inventory; ask for missing symptoms and scope rather than guessing.\n\nThe caller owns conversation state, authorisation, test execution, evidence and ticket review. Read returned references using the same published version. Never treat a requested test as already performed. A tool execution success is not proof the service is fixed. Verify the original symptom after each corrective action.\n\nDo not raise a ticket just because a source says “raise”: first establish the stated reportable-fault conditions, gather the evidence below and return a draft for RSP review. Existing incidents, in-flight orders, CPE issues and missing evidence have their own outcomes.\n\nUse the Diagnostic test information collection for source details. This tree contains supplied NBN revisions, not a guarantee of current contractual eligibility. No portal tool/API names have been invented.')
    add('nbn_tests',None,'nbn-diagnostic-tests','NBN diagnostic test information','collection',
        'Reference lookup for exact NBN test procedures, prerequisites, output interpretation, thresholds and ticket evidence. For customer symptoms enter NBN troubleshooting instead.',
        'NBN source extracts organised by technology. These are reference material, not tool definitions or executable workflow. Consult the cited PDF when a source table is ambiguous. Source metadata identifies PDF revision, section, page range and SHA-256. Text extracts omit decorative layout and figures; visually significant LED mappings are described in the diagnostic text.')
    for k,(name,tech,rev,docnum) in FILES.items():
        pdf=Path(args.pdf_dir)/name
        raw=subprocess.run([exe,'-layout',str(pdf),'-'],check=True,capture_output=True).stdout.decode('utf-8')
        pages=[clean_page(p) for p in raw.split('\f')]
        joined='\f'.join(pages)
        sid='nbn_source_'+k
        # Original is explicitly the extracted text, not a binary copy of the PDF.
        sources.append(dict(id=sid,name=name+'.extracted.txt',original=raw,hash=hashlib.sha256(raw.encode()).hexdigest(),created_at=STAMP))
        add('nbn_'+k,'nbn_troubleshooting',k,tech,'folder',f'{tech} customer faults: no service, slow speed, dropouts and equipment issues; single or multiple customer scope. Confirm this technology before entering.')
        add('nbn_tests_'+k,'nbn_tests',k,tech+' test reference','folder',f'{tech} diagnostic procedures, test limits, result interpretation and escalation evidence; source revision {rev}.')
        for key,sp,start,ep,end,title in CATALOG[k]:
            lo,hi=anchor(pages,sp,start),anchor(pages,ep,end)
            assert hi>lo,(k,key)
            content=joined[lo:hi].replace('\f','\n\n').strip()
            sections[k,key]=content
            source=dict(id=sid,name=name,type='pdf_extract',document_number=docnum,revision=rev,section=start,
                        page_start=sp,page_end=ep if anchor(pages,ep,end)>sum(len(p)+1 for p in pages[:ep-1]) else ep-1,
                        pdf_sha256=hashlib.sha256(pdf.read_bytes()).hexdigest())
            add(refid(k,key),'nbn_tests_'+k,key,title,'document',
                f'Use when {tech} requires {title[0].lower()+title[1:]}. Source section {start}, revision {rev}.',
                f'Source: {name}; revision {rev}; section {start}; PDF pages {source["page_start"]}–{source["page_end"]}.\n\n'+content,source)
        for slug,title,trigger,sourcekey in ISSUES[k]:
            rid=f'nbn_{k}_{slug.replace("-","_")}'
            source=byid[refid(k,sourcekey)]['source']
            doc=add(rid,'nbn_'+k,slug,title,'document',f'Trigger: {trigger} Start with prerequisites, follow source branches, retest after a fix, then collect only applicable escalation evidence.',source=source)
            # Native numbered blocks are bounded read units. Tables/notes without a numbered
            # boundary stay intact; source numbering is kept separately from explorer step order.
            content=sections[k,sourcekey]
            matches=list(re.finditer(r'^(\d{1,2})(?:\.\s+|\s+\|\s*|\s+(?=[A-Z]))(.+)',content,re.M))
            blocks=[]
            if matches:
                preamble=content[:matches[0].start()].strip()
                for i,m in enumerate(matches):
                    end=matches[i+1].start() if i+1<len(matches) else len(content)
                    blocks.append((m[1],content[m.start():end].strip()))
            else:
                preamble=''
                # Keep non-numbered result/threshold tables whole, bounded by source sections.
                blocks=[(None,content)]
            doc['content']=f'Trigger: {trigger}\n\nSource procedure: '+link(refid(k,sourcekey))+'.\n\n'+preamble
            intro=add(rid+'_intake',rid,'intake','Confirm scope and prerequisites','step',
                f'Entry for {trigger} Gather service and incident facts; check outages, orders, equipment and applicable basic isolation before the source procedure.',source=source)
            intro['content']='Confirm AVC/PRI, technology, ordered plan, symptom, onset/timezone, ever worked, affected service count and POI scope. Check known incidents/changes and in-flight orders before running tests. If an existing incident explains the issue, track it and retest after restoration; do not open a duplicate fault.\n\nRead and complete applicable prerequisites in their source order:\n'
            prerequisites=['portal']
            if k=='fibre': prerequisites+=['initial','led']
            elif k in ['fttn','fttc']: prerequisites+=['led','basic','shs']
            elif k=='hfc': prerequisites+=['light-actions','shs']
            else: prerequisites+=['new-service','weather','power','led','cpe']
            intro['content']+='\n'.join('- '+link(refid(k,key)) for key in prerequisites)
            intro['content']+='\n\nCaller integration rule: select only an authorised portal tool with a verified mapping to the named NBN action. Advise the user of service interruption before a disruptive test/reset. If a mapping, permission or prerequisite is missing, stop that action and record the blocker; do not invent a successful result. No test is executed by this document explorer.'
            stepnodes=[]
            for index,(native,body) in enumerate(blocks,1):
                first=re.sub(r'^\d+[.\s|]+','',body.splitlines()[0]).strip()
                label=first.split(' | ')[0]
                if ' | ' in first:
                    for line in body.splitlines()[1:7]:
                        if ' | ' not in line or line.startswith('•'): break
                        left=line.split(' | ')[0].strip()
                        if not left or left.startswith(('•','Note:')): break
                        label+=' '+left
                label=re.sub(r'\s+',' ',label).strip()
                label=(label[:53].rstrip()+'…') if len(label)>54 else (label or title)
                n=add(rid+f'_test_{index}',rid,f'test-{index}',f'NBN {"step "+native if native else "procedure"} · {label}','step',
                    f'{tech} {title}: '+re.sub(r'\s+',' ',body)[:390],source=source)
                n['content']='Follow the source conditions below. A direction to end or escalate overrides ordinary continuation. Record actual results and test IDs; retest the original symptom after any fix.\n\n'+body
                stepnodes.append((native,n))
            # Make references to uniquely numbered source steps directly actionable. Repeated
            # numbering is explicitly left for the complete source procedure, never guessed.
            counts={num:sum(x==num for x,_ in stepnodes) for num,_ in stepnodes if num}
            mapping={num:n for num,n in stepnodes if num and counts[num]==1}
            for _,n in stepnodes:
                n['content']=re.sub(r'\bstep\s*(\d+)\b',lambda m:link(mapping[m[1]]['id'],m[0]) if m[1] in mapping else m[0],n['content'],flags=re.I)
                n['content']+='\n\nComplete source procedure and source-local numbering: '+link(refid(k,sourcekey))+'.'
                if any(v>1 for v in counts.values()): n['content']+=' Source step numbers restart between phases; use the complete source procedure to disambiguate a numbered branch.'
            handoff=add(rid+'_evidence',rid,'evidence','Resolution verification and escalation evidence','step',
                f'{tech} {title}: verify restoration or assemble an evidence list with missing items, fault basis and correct escalation route; RSP reviews the draft.',source=source)
            handoff['content']='Verify the original issue with the customer and relevant post-action diagnostics.\n\nIf resolved: record before/after results, action and time, then close the troubleshooting session without an escalation ticket.\n\nIf not resolved: produce an evidence list; never fabricate test runs, IDs, observations or attachments. Mark each item collected / not applicable (with reason) / missing / unavailable. Keep missing items explicit; a draft is not evidence-complete until the applicable source requirements are met.\n\n'+evidence(k,slug)
            if k=='fibre' and slug=='dropouts':
                handoff['content']+='\nIf the optical-level check does not identify the fault and dropouts persist, complete '+link(refid(k,'data-degradation'))+' before treating the diagnostic work as complete.'
            handoff['content']+='\n\nEscalation basis: '+link(refid(k,sourcekey))+'.\nTicket requirements: '+link(refid(k,'ticket'))+'.'
            if (k,'request') in sections: handoff['content']+='\nFor a suspected network issue affecting multiple customers, also read '+link(refid(k,'request'))+'. Preserve the source distinction between per-service restoration and network investigation.'
            if k in ['fibre','hfc']: handoff['content']+='\nWhen SHS disagrees with measured symptoms, use the eligible dispute scenario and evidence requirements: '+link(refid(k,'dispute'))+'.'
            if k=='fttn': handoff['content']+='\nInterpret line rates, profile/coexistence and instability before deciding reportability: '+link(refid(k,'speed-reference'))+', '+link(refid(k,'profiles'))+', '+link(refid(k,'line-state'))+'. Repair Profile rate complaints are restricted; do not invent PI thresholds referred to an external WBA guide.'
            if k=='wireless': handoff['content']+='\nUse '+link(refid(k,'thresholds'))+' and '+link(refid(k,'optimisation'))+' to distinguish performance incidents from optimisation, local power and CPE issues.'
            handoff['content']+='\n\nCaller output contract (integration guidance, not an NBN-defined payload): resolution status; customer symptom; affected service scope; chronological tests/actions and actual IDs; evidence list with status/value/source/time; interpretation and reportable-fault basis; missing evidence/blockers; recommended ticket type and appointment need. Compose a draft for the RSP to review and explicitly approve in the portal before submission. This package does not implement portal execution, evidence storage or ticket submission.'
            ordered=[intro]+[n for _,n in stepnodes]+[handoff]
            for index,n in enumerate(ordered[:-1]):
                n['content']+='\n\nIf the source says continue and the issue remains unresolved, read '+link(ordered[index+1]['id'],'Continue in procedure order')+'. If the action resolves the issue or the source calls for escalation, read '+link(handoff['id'],'Verify resolution / assemble evidence')+'.'
            doc['content']+='\n\nStart: '+link(intro['id'])+'.'
    # Cross-section source references become exact read links where a catalog entry exists.
    for n in nodes:
        if not n['source']: continue
        k=next(k for k in FILES if n['source']['id']=='nbn_source_'+k)
        mentioned=re.findall(r'(?:Section|section|sections)\s+(\d+(?:\.\d+)+)',n['content'])
        targets=[]
        for number in mentioned:
            candidates=[refid(k,key) for key,_,section,*_ in CATALOG[k] if section==number]
            if candidates and candidates[0]!=n['id'] and candidates[0] not in targets: targets.append(candidates[0])
        if targets: n['content']+='\n\nReferenced diagnostic sections:\n'+'\n'.join('- '+link(t) for t in targets)
    # A few source defects must remain visible; do not silently turn them into invented policy.
    byid[refid('fibre','initial')]['content']+='\n\nSource ambiguity: section 3.1.1 describes V1 Wi-Fi interference within 50 cm but then says to relocate “less than 50 cm away”. These conflict. Do not infer a placement distance from that line; obtain clarification or use the referenced optimisation guide.'
    byid[refid('fibre','dispute')]['content']+='\n\nSource caution: the supplied revision prints a 0% PIR threshold for downstream profiles of 100 Mbps or less, and 70% for profiles above 100 Mbps. Preserve this literal criterion; do not apply a general 70% rule or invent a replacement threshold.'
    byid[refid('hfc','performance')]['content']+='\n\nSource limitation: section 5.8.2.4 refers to section 3.3 for HFC speed thresholds, but that section is not present in this supplied revision. No numeric HFC speed threshold is invented here. Use the actual SHS result and confirm the applicable threshold before classifying a disputed speed incident.'
    for n in nodes:
        refs={}
        for m in re.finditer(r'\[([^\]]+)\]\((viking://[^\s)]+)\)',n['content']):
            target=next((x['id'] for x in nodes if path(x['id'])==m[2]),None)
            assert target,(n['id'],m[2])
            refs[m[2]]=dict(label=m[1],target_id=target,origin='explicit_link',mention=m[2])
        n['references']=list(refs.values())
        assert len(n['content'].encode())<=100000,n['id']
    out=Path(args.output); out.parent.mkdir(parents=True,exist_ok=True)
    package=dict(format='arbor.v1',exported_at=STAMP,
                 metadata=dict(title='NBN troubleshooting and diagnostic evidence',classification='Internal NBN commercial source material',
                               source_revisions={k:v[2] for k,v in FILES.items()},workflow_count=sum(map(len,ISSUES.values())),
                               source_note='PDF-derived text, not embedded PDFs; figures are not reproduced. Exact source criteria and known ambiguities are retained.'),
                 snapshot=dict(version='draft',revision=1,nodes=nodes),sources=sources)
    out.write_text(json.dumps(package,ensure_ascii=False,indent=2)+'\n')
    print(f'{out}: {len(nodes)} nodes, {sum(map(len,ISSUES.values()))} pathways, {sum(map(len,CATALOG.values()))} diagnostic references, {len(sources)} sources, {out.stat().st_size:,} bytes')
    report=['# NBN package coverage','',f'{len(nodes)} nodes; {sum(map(len,ISSUES.values()))} pathways; {sum(map(len,CATALOG.values()))} source sections.','',
            'Troubleshooting uses four layers: domain → technology → issue → step. Diagnostic reference uses domain → technology → source procedure. Only routing needs a model; subsequent reads and branch links are deterministic.','',
            'The package contains internal NBN commercial source text. It excludes the Word design document, credentials, API settings, customer records, and test results. No PDFs are embedded. Source bodies are clearly identified text extractions; figures are not reproduced.','',
            '## Source coverage','']
    for k,v in FILES.items():
        report += [f'### {v[1]} — revision {v[2]}','', '| Source section | PDF pages | Diagnostic reference |','| --- | --- | --- |']
        for key,_,section,*_ in CATALOG[k]:
            n=byid[refid(k,key)]; s=n['source']
            report += [f'| {section} | {s["page_start"]}–{s["page_end"]} | {n["title"]} |']
        report+=['']
    report += ['## Interpretation limits','',
        '- FTTP initial-check Wi-Fi placement wording is contradictory. The reference flags this for clarification.',
        '- FTTP revision 19 prints a 0% PIR speed threshold for profiles of 100 Mbps or less; it is preserved literally, not changed to 70%.',
        '- HFC throughput text references a missing section 3.3. The package does not invent a numeric speed threshold.',
        '- FTTN/FTTB and FTTC refer to external SHS/WBA dropout criteria; those missing source criteria are not invented.',
        '- Repeated source step numbering is preserved and flagged; links to the complete procedure disambiguate the source phase.',
        '- The caller must retain evidence, execute authorised portal tools, verify restoration and obtain RSP approval for a ticket draft. These are not implemented by the knowledge explorer.','']
    Path(args.output).with_suffix('.coverage.md').write_text('\n'.join(report))

if __name__=='__main__':
    ap=argparse.ArgumentParser(); ap.add_argument('--pdf-dir',required=True); ap.add_argument('--pdftotext'); ap.add_argument('--output',default='exports/nbn-troubleshooting.arbor.json')
    build(ap.parse_args())
